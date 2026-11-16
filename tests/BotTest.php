<?php

namespace Tests;

use App\Models\Bot;
use App\Models\Chat;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * The MapChat account with a clock the tests move and a writer that notes
 * what it was shown instead of calling Groq
 */
class TestBot extends Bot
{
    public static int $later = 0;

    /** [instructions, chat] for each reply written */
    public static array $asked = [];

    public static function reset(): void
    {
        static::$later = 0;
        static::$asked = [];
        static::$writer = function ($situation, $chat) {
            static::$asked[] = [$situation, $chat];
            return 'reply ' . count(static::$asked);
        };
    }

    public static function useReply(string $reply): void
    {
        static::$writer = fn () => $reply;
    }

    public static function forget(): void
    {
        static::$writer = null;
    }

    protected static function now()
    {
        return time() + static::$later;
    }
}

/**
 * When MapChat greets, answers and keeps quiet. Each test runs in a
 * transaction that is rolled back
 */
class BotTest extends TestCase
{
    const ANN = 'ann@test.local';
    const BOB = 'bob@test.local';
    const CAT = 'cat@test.local';

    private PDO $db;

    protected function setUp(): void
    {
        $this->db = TestDb::get();
        $this->db->beginTransaction();

        foreach ([self::ANN, self::BOB, self::CAT] as $email) {
            $this->db->prepare("INSERT INTO users (name, email, password_hash, is_active, is_guest) VALUES (?, ?, '!', TRUE, ?)")
                ->execute([ucfirst(strtok($email, '@')), $email, (int) ($email === self::CAT)]);
            // Everyone has the chat open, so others could answer a visitor
            $this->db->prepare("INSERT INTO userinfo (email, ip, lat, lng, city, country, online_at) VALUES (?, '8.8.8.8', 1, 2, 'Dhaka', 'BD', NOW())")
                ->execute([$email]);
        }

        TestBot::reset();
    }

    protected function tearDown(): void
    {
        TestBot::forget();
        $this->db->rollBack();
    }

    /**
     * Send a message the way the chat page does, and return its id
     */
    private function send(string $from, string $body, ?string $to = null): int
    {
        (new Chat(['email' => $from, 'to_email' => $to, 'body' => $body]))->send();
        $id = (int) Chat::lastId();
        TestBot::heard($from, $id, $to, $body);

        return $id;
    }

    /**
     * Move the clock on and let the polling run what's due
     */
    private function wait(int $seconds): void
    {
        TestBot::$later += $seconds;
        TestBot::runDue();
    }

    /**
     * Add a demo person, as demo.sql does
     */
    private function addDemoPerson(string $name, string $city): string
    {
        $email = strtolower(strtok($name, ' ')) . '@demo.mapchat.local';
        $this->db->prepare("INSERT INTO users (name, email, password_hash, is_active) VALUES (?, ?, '!', TRUE)")
            ->execute([$name, $email]);
        $this->db->prepare("INSERT INTO userinfo (email, ip, lat, lng, city, country) VALUES (?, '0.0.0.0', 1, 2, ?, 'CA')")
            ->execute([$email, $city]);

        return $email;
    }

    /**
     * Every answer posted since the test began, as [who, body]
     */
    private function answers(): array
    {
        return $this->db->query('SELECT email, body FROM chats WHERE id IN (SELECT posted_id FROM mapchat_jobs) ORDER BY id')
            ->fetchAll(PDO::FETCH_NUM);
    }

    /**
     * MapChat's messages since the test began, as [to_email, body]
     */
    private function botSaid(): array
    {
        $stmt = $this->db->prepare('SELECT to_email, body FROM chats
                                    WHERE email = ? AND id IN (SELECT posted_id FROM mapchat_jobs) ORDER BY id');
        $stmt->execute([Chat::BOT_EMAIL]);

        return $stmt->fetchAll(PDO::FETCH_NUM);
    }

    public function testQuietRoomGreetsANewVisitor()
    {
        TestBot::arrived(self::ANN);

        $this->wait(1);
        $this->assertSame([], $this->botSaid(), 'not before they have a chance to speak');

        $this->wait(Bot::GREET_SECONDS);
        $said = $this->botSaid();
        $this->assertCount(1, $said);
        $this->assertNull($said[0][0], 'in the open room');
        $this->assertStringStartsWith('@Ann ', $said[0][1]);
    }

    public function testBusyRoomGetsNoGreeting()
    {
        $this->send(self::BOB, 'anyone watching the match?');
        $this->send(self::CAT, 'me! 2-1 so far');
        TestBot::arrived(self::ANN);

        $this->wait(Bot::GREET_SECONDS + Bot::GRACE_SECONDS);

        $this->assertSame([], $this->botSaid());
    }

    public function testDuplicateJoinsGreetOnce()
    {
        // Two tabs, then a reload
        TestBot::arrived(self::ANN);
        TestBot::arrived(self::ANN);
        $this->wait(Bot::GREET_SECONDS);
        TestBot::arrived(self::ANN);
        $this->wait(Bot::GREET_SECONDS + Bot::ROOM_COOLDOWN_SECONDS);

        $this->assertCount(1, $this->botSaid());

        // The next day they're new again
        $this->wait(Bot::GREET_COOLDOWN_HOURS * 3600);
        TestBot::arrived(self::ANN);
        $this->wait(Bot::GREET_SECONDS);

        $this->assertCount(2, $this->botSaid());
    }

    public function testVisitorWhoSpeaksFirstGetsAReplyNotAGreeting()
    {
        TestBot::arrived(self::ANN);
        $this->wait(5);
        $this->send(self::ANN, 'hello? is anyone here');

        $this->wait(Bot::GREET_SECONDS);
        $this->assertSame([], $this->botSaid(), 'nobody else has had their chance to answer yet');

        $this->wait(Bot::GRACE_SECONDS);
        $this->assertSame([[null, 'reply 1']], $this->botSaid());
    }

    public function testVisitorAloneGetsAQuickReply()
    {
        $this->db->prepare('UPDATE userinfo SET online_at = NULL WHERE email IN (?, ?)')->execute([self::BOB, self::CAT]);
        $this->send(self::ANN, 'hello? is anyone here');

        $this->wait(Bot::FOLLOW_UP_SECONDS);

        $this->assertSame([[null, 'reply 1']], $this->botSaid());
    }

    public function testNoReplyWhenSomeoneAnswersDuringTheGracePeriod()
    {
        $this->send(self::ANN, 'hello? is anyone here');
        $this->wait(10);
        $this->send(self::BOB, 'hi ann! welcome');

        $this->wait(Bot::GRACE_SECONDS);

        $this->assertSame([], $this->botSaid());
        $this->assertSame([], TestBot::$asked);
    }

    public function testFollowUpIsAnsweredWithoutAMention()
    {
        $this->send(self::ANN, 'hello? is anyone here');
        $this->wait(Bot::GRACE_SECONDS);
        $this->send(self::ANN, 'oh hi, what is this place?');

        $this->wait(Bot::FOLLOW_UP_SECONDS + Bot::ROOM_COOLDOWN_SECONDS);

        $this->assertSame([[null, 'reply 1'], [null, 'reply 2']], $this->botSaid());
        $this->assertStringContainsString("MapChat (you): reply 1\nAnn: oh hi, what is this place?", TestBot::$asked[1][1]);
    }

    public function testGreetedVisitorWhoAnswersGetsAFollowUp()
    {
        TestBot::arrived(self::ANN);
        $this->wait(Bot::GREET_SECONDS);
        $this->send(self::ANN, 'sunny!');

        $this->wait(Bot::ROOM_COOLDOWN_SECONDS);

        $this->assertCount(2, $this->botSaid());
        $this->assertSame('reply 1', $this->botSaid()[1][1]);
    }

    public function testStopsWhilePeopleTalkToEachOther()
    {
        $this->send(self::ANN, 'hello? is anyone here');
        $this->wait(Bot::GRACE_SECONDS);
        $this->send(self::BOB, 'hey ann, i am!');
        $this->send(self::ANN, 'oh nice, hi bob');

        $this->wait(Bot::GRACE_SECONDS + Bot::ROOM_COOLDOWN_SECONDS);

        $this->assertSame([[null, 'reply 1']], $this->botSaid());
    }

    public function testRepliesWaitForTheRoomCooldown()
    {
        TestBot::arrived(self::ANN);
        TestBot::arrived(self::BOB);
        $this->wait(Bot::GREET_SECONDS);

        $this->assertCount(1, $this->botSaid());

        $this->wait(Bot::ROOM_COOLDOWN_SECONDS);
        $this->assertCount(2, $this->botSaid());
    }

    public function testRoomRepliesLeaveTheDemoPeopleOut()
    {
        $maya = 'maya@demo.mapchat.local';
        $this->db->prepare("INSERT INTO users (name, email, password_hash, is_active) VALUES ('Maya', ?, '!', TRUE)")
            ->execute([$maya]);
        $this->send($maya, 'i want to move to Null Island');
        $this->send(self::ANN, 'hello? is anyone here');

        $this->wait(Bot::GRACE_SECONDS);

        $this->assertCount(1, TestBot::$asked);
        $this->assertStringContainsString('hello? is anyone here', TestBot::$asked[0][1]);
        $this->assertStringNotContainsString('Null Island', TestBot::$asked[0][1]);
    }

    public function testBotAndDemoMessagesNeverMakeJobs()
    {
        $this->send(Chat::BOT_EMAIL, 'Welcome to MapChat!');
        Bot::heard('maya@demo.mapchat.local', 1, null, '@MapChat hi');
        Bot::arrived('maya@demo.mapchat.local');
        Bot::arrived(Chat::BOT_EMAIL);

        $this->assertSame(0, (int) $this->db->query('SELECT COUNT(*) FROM mapchat_jobs')->fetchColumn());
    }

    public function testPrivateMessagesAreAnsweredInPrivate()
    {
        $this->send(self::ANN, 'what can you do?', Chat::BOT_EMAIL);

        $this->wait(Bot::PRIVATE_SECONDS);

        $this->assertSame([[self::ANN, 'reply 1']], $this->botSaid());
    }

    public function testPublicAndPrivateConversationsStayApart()
    {
        $this->send(self::ANN, 'hello room');
        $this->send(self::ANN, 'ann secret for mapchat', Chat::BOT_EMAIL);
        $this->send(self::CAT, 'cat secret for mapchat', Chat::BOT_EMAIL);
        $this->send(self::BOB, 'bob secret for ann', self::ANN);

        $this->wait(Bot::GRACE_SECONDS);

        $this->assertCount(3, TestBot::$asked);
        $chats = [];
        foreach (TestBot::$asked as [$situation, $chat]) {
            $chats[str_contains($situation, "You're in the open room") ? 'room' : (str_contains($chat, 'Ann:') ? 'ann' : 'cat')] = $chat;
        }

        $this->assertStringContainsString('hello room', $chats['room']);
        $this->assertStringNotContainsString('secret', $chats['room']);

        $this->assertStringContainsString('ann secret for mapchat', $chats['ann']);
        $this->assertStringNotContainsString('hello room', $chats['ann']);
        $this->assertStringNotContainsString('cat secret', $chats['ann']);
        $this->assertStringNotContainsString('bob secret', $chats['ann']);

        $this->assertStringContainsString('cat secret for mapchat', $chats['cat']);
        $this->assertStringNotContainsString('ann secret', $chats['cat']);
    }

    public function testMapChatIsOnlineWhileItCanChatBack()
    {
        $people = array_column(\App\Models\UserInfo::getRecentPeople(), null, 'name');
        $this->assertEquals(1, $people['MapChat']['is_online']);
        $this->assertEquals(1, $people['MapChat']['is_bot']);
        $this->assertEquals(0, $people['Ann']['is_bot']);

        TestBot::forget();
        $people = array_column(\App\Models\UserInfo::getRecentPeople(), 'is_online', 'name');
        $this->assertArrayNotHasKey('MapChat', $people);
    }

    public function testRepliesHaveNoLongDashes()
    {
        TestBot::useReply('Sure — the map shows cities – roughly.');
        $this->send(self::ANN, 'what is this?', Chat::BOT_EMAIL);

        $this->wait(Bot::PRIVATE_SECONDS);

        $this->assertSame([[self::ANN, 'Sure, the map shows cities, roughly.']], $this->botSaid());
    }

    public function testRepliesDontIntroduceThemselves()
    {
        TestBot::useReply('MapChat here, the AI for this map chat. The map shows pins for each city.');
        $this->send(self::ANN, 'what is this?', Chat::BOT_EMAIL);

        $this->wait(Bot::PRIVATE_SECONDS);

        $this->assertSame([[self::ANN, 'The map shows pins for each city.']], $this->botSaid());
    }

    public function testMentionIsAnsweredEvenWhilePeopleTalk()
    {
        $this->send(self::ANN, 'anyone watching the match?');
        $this->send(self::BOB, 'me! 2-1 so far');
        $this->send(self::CAT, '@mapchat where is Null Island?');
        $this->send(self::BOB, 'cat, it is at 0,0');

        $this->wait(Bot::FOLLOW_UP_SECONDS);

        $this->assertSame([[null, '@Cat reply 1']], $this->botSaid(), 'tagged, as Bob spoke in between');
        $this->assertStringEndsWith("Cat: @mapchat where is Null Island?", TestBot::$asked[0][1]);
    }

    public function testPrivateMessagesDontNeedOrUseMentions()
    {
        $this->send(self::ANN, '@MapChat hi', self::BOB);
        $this->send(self::ANN, 'hi', Chat::BOT_EMAIL);

        $this->wait(Bot::PRIVATE_SECONDS);

        $this->assertSame([[self::ANN, 'reply 1']], $this->botSaid());
    }

    public function testReplyIsTaggedOnlyWhenOtherMessagesCameBetween()
    {
        $maya = 'maya@demo.mapchat.local';
        $this->db->prepare("INSERT INTO users (name, email, password_hash, is_active) VALUES ('Maya', ?, '!', TRUE)")
            ->execute([$maya]);

        $this->send(self::ANN, 'hello? is anyone here');
        $this->wait(Bot::GRACE_SECONDS);
        $this->send(self::ANN, 'what is this place?');
        $this->send($maya, 'i want to move to Null Island');
        $this->wait(Bot::ROOM_COOLDOWN_SECONDS);

        $this->assertSame([[null, 'reply 1'], [null, '@Ann reply 2']], $this->botSaid());
    }

    public function testReplyThatMentionsThemIsntTaggedTwice()
    {
        TestBot::useReply('good question @ann!');
        $this->send(self::ANN, '@MapChat what is this?');
        $this->send(self::BOB, 'hi all');

        $this->wait(Bot::FOLLOW_UP_SECONDS);

        $this->assertSame([[null, 'good question @ann!']], $this->botSaid());
    }

    public function testTagsByNickname()
    {
        $this->db->prepare("UPDATE users SET nickname = 'annie' WHERE email = ?")->execute([self::ANN]);
        $this->send(self::ANN, '@MapChat hi');
        $this->send(self::BOB, 'hello');

        $this->wait(Bot::FOLLOW_UP_SECONDS);

        $this->assertSame([[null, '@annie reply 1']], $this->botSaid());
        $this->assertStringContainsString('annie: @MapChat hi', TestBot::$asked[0][1]);
    }

    public function testMentionedDemoPersonAnswersInCharacter()
    {
        $maya = $this->addDemoPerson('Maya Thompson', 'Toronto');
        $this->send(self::ANN, '@Maya how is Toronto today?');

        $this->wait(Bot::FOLLOW_UP_SECONDS);

        $this->assertSame([[$maya, 'reply 1']], $this->answers(), 'Maya answers, and MapChat stays out of it');
        $this->assertStringContainsString('You are Maya Thompson', TestBot::$asked[0][0]);
        $this->assertStringContainsString('Toronto', TestBot::$asked[0][0]);
        $this->assertStringContainsString("digital characters, written by AI", TestBot::$asked[0][0]);
    }

    public function testGuestKeepsTalkingWithTheDemoPerson()
    {
        $maya = $this->addDemoPerson('Maya Thompson', 'Toronto');
        $this->send(self::ANN, '@Maya hi!');
        $this->wait(Bot::FOLLOW_UP_SECONDS);
        $this->send(self::ANN, 'what do you do there?');

        $this->wait(Bot::ROOM_COOLDOWN_SECONDS);

        $this->assertSame([[$maya, 'reply 1'], [$maya, 'reply 2']], $this->answers());
        $this->assertStringContainsString("Maya (you): reply 1\nAnn: what do you do there?", TestBot::$asked[1][1]);
    }

    public function testDemoPersonReadsTheirOwnLinesButNotTheOtherScript()
    {
        $maya = $this->addDemoPerson('Maya Thompson', 'Toronto');
        $lukas = $this->addDemoPerson('Lukas Becker', 'Berlin');
        $this->send($maya, 'anyone else up early?');
        $this->send($lukas, 'my team lost again');
        $this->send(self::ANN, '@Maya me!');

        $this->wait(Bot::FOLLOW_UP_SECONDS);

        $this->assertStringContainsString('Maya (you): anyone else up early?', TestBot::$asked[0][1]);
        $this->assertStringNotContainsString('my team lost', TestBot::$asked[0][1]);
    }

    public function testDemoPersonAnswersPrivatelyFromThatChatOnly()
    {
        $maya = $this->addDemoPerson('Maya Thompson', 'Toronto');
        $this->send(self::ANN, 'hello room');
        $this->send(self::ANN, 'hi maya, ann here', $maya);
        $this->send(self::CAT, 'cat secret for maya', $maya);
        $this->send(self::ANN, 'ann secret for mapchat', Chat::BOT_EMAIL);

        $this->wait(Bot::PRIVATE_SECONDS);

        $answers = $this->db->query('SELECT email, to_email FROM chats WHERE id IN (SELECT posted_id FROM mapchat_jobs) ORDER BY id')
            ->fetchAll(PDO::FETCH_NUM);
        $this->assertContains([$maya, self::ANN], $answers);
        $this->assertContains([$maya, self::CAT], $answers);
        $this->assertContains([Chat::BOT_EMAIL, self::ANN], $answers);

        $mayaToAnn = array_values(array_filter(TestBot::$asked, fn ($asked) => str_contains($asked[1], 'hi maya')))[0];
        $this->assertStringContainsString('You are Maya Thompson', $mayaToAnn[0]);
        $this->assertStringContainsString('private chat with Ann', $mayaToAnn[0]);
        $this->assertStringNotContainsString('hello room', $mayaToAnn[1]);
        $this->assertStringNotContainsString('cat secret', $mayaToAnn[1]);
        $this->assertStringNotContainsString('mapchat', $mayaToAnn[1]);
    }
}

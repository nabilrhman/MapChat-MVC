<?php

namespace Tests;

use App\Models\Bot;
use App\Models\Chat;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * The MapChat account with a clock the tests move and a writer that notes
 * what it was shown instead of calling the Claude API
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
            $this->db->prepare("INSERT INTO userinfo (email, ip, lat, lng, city, country) VALUES (?, '8.8.8.8', 1, 2, 'Dhaka', 'BD')")
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
        TestBot::heard($from, $id, $to);

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
        $this->assertStringContainsString('Ann', $said[0][1]);
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

    public function testBotAndDemoMessagesNeverMakeJobs()
    {
        $this->send(Chat::BOT_EMAIL, 'Welcome to MapChat!');
        Bot::heard('maya@demo.mapchat.local', 1, null);
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
            $chats[str_contains($situation, 'open room') ? 'room' : (str_contains($chat, 'Ann:') ? 'ann' : 'cat')] = $chat;
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
}

<?php

namespace Tests;

use App\Models\Bot;
use App\Models\Chat;
use App\Models\DemoChat;
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

    /** Seconds a private reply takes to "type"; null for the real time */
    public static ?float $typing = 0.0;

    /**
     * Start a test: the clock now, nothing asked, a writer that notes what it was shown
     */
    public static function reset(): void
    {
        static::$later = 0;
        static::$asked = [];
        static::$typing = 0.0;
        static::$writer = function ($situation, $chat) {
            static::$asked[] = [$situation, $chat];
            return 'reply ' . count(static::$asked);
        };
    }

    /**
     * Have the writer give this reply
     */
    public static function useReply(string $reply): void
    {
        static::$writer = fn () => $reply;
    }

    /**
     * Go back to the real writer
     */
    public static function forget(): void
    {
        static::$writer = null;
    }

    /**
     * The real time, moved on by $later
     */
    protected static function now(): int
    {
        return time() + static::$later;
    }

    /**
     * How long a reply takes to "type": $typing, or the real time
     */
    protected static function typingSeconds(string $body): float
    {
        return static::$typing ?? parent::typingSeconds($body);
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
            TestDb::createUser(ucfirst(strtok($email, '@')), $email, isGuest: $email === self::CAT);
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
        $id = Chat::save($from, $to, $body);
        TestBot::onMessage($from, $id, $to, $body);

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
        TestDb::createUser($name, $email);
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

    public function testQuietRoomGreetsANewVisitor(): void
    {
        TestBot::onArrival(self::ANN);

        $this->wait(1);
        $this->assertSame([], $this->botSaid(), 'not before they have a chance to speak');

        $this->wait(Bot::GREET_SECONDS);
        $said = $this->botSaid();
        $this->assertCount(1, $said);
        $this->assertNull($said[0][0], 'in the open room');
        $this->assertStringStartsWith('@Ann ', $said[0][1]);
    }

    public function testBusyRoomGetsNoGreeting(): void
    {
        $this->send(self::BOB, 'anyone watching the match?');
        $this->send(self::CAT, 'me! 2-1 so far');
        TestBot::onArrival(self::ANN);

        $this->wait(Bot::GREET_SECONDS + Bot::GRACE_SECONDS);

        $this->assertSame([], $this->botSaid());
    }

    public function testDuplicateJoinsGreetOnce(): void
    {
        // Two tabs, then a reload
        TestBot::onArrival(self::ANN);
        TestBot::onArrival(self::ANN);
        $this->wait(Bot::GREET_SECONDS);
        TestBot::onArrival(self::ANN);
        $this->wait(Bot::GREET_SECONDS + Bot::ROOM_COOLDOWN_SECONDS);

        $this->assertCount(1, $this->botSaid());

        // The next day they're new again
        $this->wait(Bot::GREET_COOLDOWN_HOURS * 3600);
        TestBot::onArrival(self::ANN);
        $this->wait(Bot::GREET_SECONDS);

        $this->assertCount(2, $this->botSaid());
    }

    public function testVisitorWhoSpeaksFirstGetsAReplyNotAGreeting(): void
    {
        TestBot::onArrival(self::ANN);
        $this->wait(5);
        $this->send(self::ANN, 'hello? is anyone here');

        $this->wait(Bot::GREET_SECONDS);
        $this->assertSame([], $this->botSaid(), 'nobody else has had their chance to answer yet');

        $this->wait(Bot::GRACE_SECONDS);
        $this->assertSame([[null, 'reply 1']], $this->botSaid());
    }

    public function testVisitorAloneGetsAQuickReply(): void
    {
        $this->db->prepare('UPDATE userinfo SET online_at = NULL WHERE email IN (?, ?)')->execute([self::BOB, self::CAT]);
        $this->send(self::ANN, 'hello? is anyone here');

        $this->wait(Bot::FOLLOW_UP_SECONDS);

        $this->assertSame([[null, 'reply 1']], $this->botSaid());
    }

    public function testNoReplyWhenSomeoneAnswersDuringTheGracePeriod(): void
    {
        $this->send(self::ANN, 'hello? is anyone here');
        $this->wait(10);
        $this->send(self::BOB, 'hi ann! welcome');

        $this->wait(Bot::GRACE_SECONDS);

        $this->assertSame([], $this->botSaid());
        $this->assertSame([], TestBot::$asked);
    }

    public function testFollowUpIsAnsweredWithoutAMention(): void
    {
        $this->send(self::ANN, 'hello? is anyone here');
        $this->wait(Bot::GRACE_SECONDS);
        $this->send(self::ANN, 'oh hi, what is this place?');

        $this->wait(Bot::FOLLOW_UP_SECONDS + Bot::ROOM_COOLDOWN_SECONDS);

        $this->assertSame([[null, 'reply 1'], [null, 'reply 2']], $this->botSaid());
        $this->assertStringContainsString("MapChat (you): reply 1\nAnn: oh hi, what is this place?", TestBot::$asked[1][1]);
    }

    public function testGreetedVisitorWhoAnswersGetsAFollowUp(): void
    {
        TestBot::onArrival(self::ANN);
        $this->wait(Bot::GREET_SECONDS);
        $this->send(self::ANN, 'sunny!');

        $this->wait(Bot::ROOM_COOLDOWN_SECONDS);

        $this->assertCount(2, $this->botSaid());
        $this->assertSame('reply 1', $this->botSaid()[1][1]);
    }

    public function testStopsWhilePeopleTalkToEachOther(): void
    {
        $this->send(self::ANN, 'hello? is anyone here');
        $this->wait(Bot::GRACE_SECONDS);
        $this->send(self::BOB, 'hey ann, i am!');
        $this->send(self::ANN, 'oh nice, hi bob');

        $this->wait(Bot::GRACE_SECONDS + Bot::ROOM_COOLDOWN_SECONDS);

        $this->assertSame([[null, 'reply 1']], $this->botSaid());
    }

    public function testRepliesWaitForTheRoomCooldown(): void
    {
        TestBot::onArrival(self::ANN);
        TestBot::onArrival(self::BOB);
        $this->wait(Bot::GREET_SECONDS);

        $this->assertCount(1, $this->botSaid());

        $this->wait(Bot::ROOM_COOLDOWN_SECONDS);
        $this->assertCount(2, $this->botSaid());
    }

    public function testRoomRepliesLeaveTheDemoPeopleOut(): void
    {
        $maya = 'maya@demo.mapchat.local';
        TestDb::createUser('Maya', $maya);
        $this->send($maya, 'i want to move to Null Island');
        $this->send(self::ANN, 'hello? is anyone here');

        $this->wait(Bot::GRACE_SECONDS);

        $this->assertCount(1, TestBot::$asked);
        $this->assertStringContainsString('hello? is anyone here', TestBot::$asked[0][1]);
        $this->assertStringNotContainsString('Null Island', TestBot::$asked[0][1]);
    }

    public function testBotAndDemoMessagesNeverMakeJobs(): void
    {
        $this->send(Chat::BOT_EMAIL, 'Welcome to MapChat!');
        Bot::onMessage('maya@demo.mapchat.local', 1, null, '@MapChat hi');
        Bot::onArrival('maya@demo.mapchat.local');
        Bot::onArrival(Chat::BOT_EMAIL);

        $this->assertSame(0, (int) $this->db->query('SELECT COUNT(*) FROM mapchat_jobs')->fetchColumn());
    }

    public function testPrivateMessagesAreAnsweredInPrivate(): void
    {
        $this->send(self::ANN, 'what can you do?', Chat::BOT_EMAIL);

        $this->wait(Bot::PRIVATE_SECONDS);

        $this->assertSame([[self::ANN, 'reply 1']], $this->botSaid());
    }

    public function testPublicAndPrivateConversationsStayApart(): void
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

    public function testMapChatIsOnlineWhileItCanChatBack(): void
    {
        $people = array_column(\App\Models\UserInfo::getRecentPeople(), null, 'name');
        $this->assertEquals(1, $people['MapChat']['is_online']);
        $this->assertEquals(1, $people['MapChat']['is_bot']);
        $this->assertEquals(0, $people['Ann']['is_bot']);

        TestBot::forget();
        $people = array_column(\App\Models\UserInfo::getRecentPeople(), 'is_online', 'name');
        $this->assertArrayNotHasKey('MapChat', $people);
    }

    public function testRepliesHaveNoLongDashes(): void
    {
        TestBot::useReply('Sure — the map shows cities – roughly.');
        $this->send(self::ANN, 'what is this?', Chat::BOT_EMAIL);

        $this->wait(Bot::PRIVATE_SECONDS);

        $this->assertSame([[self::ANN, 'Sure, the map shows cities, roughly.']], $this->botSaid());
    }

    public function testRepliesDontIntroduceThemselves(): void
    {
        TestBot::useReply('MapChat here, the AI for this map chat. The map shows pins for each city.');
        $this->send(self::ANN, 'what is this?', Chat::BOT_EMAIL);

        $this->wait(Bot::PRIVATE_SECONDS);

        $this->assertSame([[self::ANN, 'The map shows pins for each city.']], $this->botSaid());
    }

    public function testMentionIsAnsweredEvenWhilePeopleTalk(): void
    {
        $this->send(self::ANN, 'anyone watching the match?');
        $this->send(self::BOB, 'me! 2-1 so far');
        $this->send(self::CAT, '@mapchat where is Null Island?');
        $this->send(self::BOB, 'cat, it is at 0,0');

        $this->wait(Bot::FOLLOW_UP_SECONDS);

        $this->assertSame([[null, '@Cat reply 1']], $this->botSaid(), 'tagged, as Bob spoke in between');
        $this->assertStringEndsWith("Cat: @mapchat where is Null Island?", TestBot::$asked[0][1]);
    }

    public function testPrivateMessagesDontNeedOrUseMentions(): void
    {
        $this->send(self::ANN, '@MapChat hi', self::BOB);
        $this->send(self::ANN, 'hi', Chat::BOT_EMAIL);

        $this->wait(Bot::PRIVATE_SECONDS);

        $this->assertSame([[self::ANN, 'reply 1']], $this->botSaid());
    }

    public function testReplyIsTaggedOnlyWhenOtherMessagesCameBetween(): void
    {
        $maya = 'maya@demo.mapchat.local';
        TestDb::createUser('Maya', $maya);

        $this->send(self::ANN, 'hello? is anyone here');
        $this->wait(Bot::GRACE_SECONDS);
        $this->send(self::ANN, 'what is this place?');
        $this->send($maya, 'i want to move to Null Island');
        $this->wait(Bot::ROOM_COOLDOWN_SECONDS);

        $this->assertSame([[null, 'reply 1'], [null, '@Ann reply 2']], $this->botSaid());
    }

    public function testReplyThatMentionsThemIsntTaggedTwice(): void
    {
        TestBot::useReply('good question @ann!');
        $this->send(self::ANN, '@MapChat what is this?');
        $this->send(self::BOB, 'hi all');

        $this->wait(Bot::FOLLOW_UP_SECONDS);

        $this->assertSame([[null, 'good question @ann!']], $this->botSaid());
    }

    public function testTagsByNickname(): void
    {
        $this->db->prepare("UPDATE users SET nickname = 'annie' WHERE email = ?")->execute([self::ANN]);
        $this->send(self::ANN, '@MapChat hi');
        $this->send(self::BOB, 'hello');

        $this->wait(Bot::FOLLOW_UP_SECONDS);

        $this->assertSame([[null, '@annie reply 1']], $this->botSaid());
        $this->assertStringContainsString('annie: @MapChat hi', TestBot::$asked[0][1]);
    }

    public function testMentionedDemoPersonAnswersInCharacter(): void
    {
        $maya = $this->addDemoPerson('Maya Thompson', 'Toronto');
        $this->send(self::ANN, '@Maya how is Toronto today?');

        $this->wait(Bot::COME_ONLINE_SECONDS + Bot::DEMO_ANSWER_SECONDS);

        $this->assertSame([[$maya, '@Ann reply 1']], $this->answers(), 'Maya answers, tagging Ann, and MapChat stays out of it');
        $this->assertStringContainsString('You are Maya Thompson', TestBot::$asked[0][0]);
        $this->assertStringContainsString('Toronto', TestBot::$asked[0][0]);
        $this->assertStringContainsString("digital characters, written by AI", TestBot::$asked[0][0]);
    }

    public function testGuestKeepsTalkingWithTheDemoPerson(): void
    {
        $maya = $this->addDemoPerson('Maya Thompson', 'Toronto');
        $this->send(self::ANN, '@Maya hi!');
        $this->wait(Bot::COME_ONLINE_SECONDS + Bot::DEMO_ANSWER_SECONDS);
        $this->send(self::ANN, 'what do you do there?');

        $this->wait(Bot::ROOM_COOLDOWN_SECONDS);

        $this->assertSame([[$maya, '@Ann reply 1'], [$maya, '@Ann reply 2']], $this->answers());
        $this->assertStringContainsString("Maya (you): @Ann reply 1\nAnn: what do you do there?", TestBot::$asked[1][1]);
    }

    public function testDemoPersonReadsTheSceneTheyWereSummonedInto(): void
    {
        $maya = $this->addDemoPerson('Maya Thompson', 'Toronto');
        $lukas = $this->addDemoPerson('Lukas Becker', 'Berlin');
        $this->send($maya, '@Lukas any news on the mystery parcel?');
        $this->send($lukas, 'it was socks. forty pairs of socks');
        $this->send(self::ANN, '@Maya what parcel?');

        $this->wait(Bot::COME_ONLINE_SECONDS + Bot::DEMO_ANSWER_SECONDS);

        $this->assertStringContainsString(
            "Maya (you): @Lukas any news on the mystery parcel?\nLukas: it was socks. forty pairs of socks\nAnn: @Maya what parcel?",
            TestBot::$asked[0][1]
        );
    }

    public function testDemoPersonAnswersPrivatelyFromThatChatOnly(): void
    {
        $maya = $this->addDemoPerson('Maya Thompson', 'Toronto');
        $this->send(self::ANN, 'hello room');
        $this->send(self::ANN, 'hi maya, ann here', $maya);
        $this->send(self::CAT, 'cat secret for maya', $maya);
        $this->send(self::ANN, 'ann secret for mapchat', Chat::BOT_EMAIL);

        $this->wait(Bot::COME_ONLINE_SECONDS + Bot::DEMO_ANSWER_SECONDS);

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

    public function testStoryWaitsWhileGuestsTalkWithDemoPeople(): void
    {
        $maya = $this->addDemoPerson('Maya Thompson', 'Toronto');
        $add = $this->db->prepare("INSERT INTO mapchat_jobs (kind, email, speaker, dedupe_key, due_at, created_at, done_at)
                                   VALUES ('reply', ?, ?, ?, ?, ?, ?)");
        $at = fn ($seconds) => $seconds === null ? null : gmdate('Y-m-d H:i:s', time() + $seconds);
        foreach ([[-1000, -997], [-800, -797], [-100, null]] as $i => [$created, $done]) {
            $add->execute([self::ANN, $maya, "test:$i", $at($created), $at($created), $at($done)]);
        }
        // MapChat's own conversations don't hold the story
        $add->execute([self::ANN, Chat::BOT_EMAIL, 'test:mapchat', $at(-5000), $at(-5000), $at(-4997)]);

        // Two overlapping talks, each quiet CONVERSATION_MINUTES after the
        // last answer, then one still waiting for its answer
        $quiet = Bot::CONVERSATION_MINUTES * 60;
        $this->assertEqualsWithDelta((-797 + $quiet) - (-1000) + 100, DemoChat::storyPausedSeconds(time() - 86400), 2);
    }

    public function testAShortNoClosesTheChatWithoutAnotherQuestion(): void
    {
        $this->send(self::ANN, 'what is this place?', Chat::BOT_EMAIL);
        $this->wait(Bot::PRIVATE_SECONDS);
        $this->send(self::ANN, 'Nothing.', Chat::BOT_EMAIL);
        $this->wait(Bot::PRIVATE_SECONDS);

        $this->assertStringNotContainsString('done chatting', TestBot::$asked[0][0]);
        $this->assertStringContainsString('done chatting for now', TestBot::$asked[1][0]);
    }

    /**
     * Post demo lines as today's script does, oldest first
     */
    private function postScript(array $lines): void
    {
        $add = $this->db->prepare('INSERT INTO chats (email, body, timestamp) VALUES (?, ?, ?)');
        foreach ($lines as [$who, $body]) {
            $add->execute([$who . '@demo.mapchat.local', $body, gmdate('Y-m-d H:i:s')]);
        }
    }

    public function testDemoPersonKnowsTheirStoryFromHoursAgo(): void
    {
        $emily = $this->addDemoPerson('Emily Carter', 'Boise');
        foreach ([['Aiko Tanaka', 'Tokyo'], ['Lukas Becker', 'Berlin'], ['Sofia Ramírez', 'Mexico City'], ['Jae-won Park', 'Seoul']] as [$name, $city]) {
            $this->addDemoPerson($name, $city);
        }
        // The sourdough story's first two scenes, of three
        $this->postScript([
            ['emily', 'i started a sourdough starter. she\'s called Doughlores 🍞'],
            ['lukas', 'strong name'],
            ['emily', 'update: Doughlores escaped 😱 she pushed the lid off overnight'],
            ['aiko', '@Emily she wants to see the world 😂'],
            ['emily', 'moved her to a bigger jar. she looks very pleased with herself'],
        ]);
        // Then the room moves on, past what a reply reads of it
        for ($i = 0; $i < Bot::CONTEXT_MESSAGES + 5; $i++) {
            $this->send(self::BOB, "something else $i");
        }

        $this->send(self::ANN, '@Emily what happened with Doughlores?');
        $this->wait(Bot::COME_ONLINE_SECONDS + Bot::DEMO_ANSWER_SECONDS);

        $emilys = array_values(array_filter(TestBot::$asked, fn ($asked) => str_contains($asked[0], 'You are Emily')));
        $this->assertCount(1, $emilys);
        [$instructions, $chat] = $emilys[0];
        $this->assertStringNotContainsString('Doughlores escaped', $chat, 'too long ago for the room it reads');
        $this->assertStringContainsString(
            "Emily (you): update: Doughlores escaped 😱 she pushed the lid off overnight\nAiko: @Emily she wants to see the world 😂",
            $instructions
        );
        $this->assertStringContainsString('Not over yet', $instructions, 'her first loaf is still to come');
        $this->assertStringNotContainsString('FIRST LOAF', $instructions);
    }

    public function testDemoPersonKnowsTheirStoryInPrivateWithoutItsEnding(): void
    {
        $tanvir = $this->addDemoPerson('Tanvir Hasan', 'Dhaka');
        $this->addDemoPerson('Sofia Ramírez', 'Mexico City');
        $this->addDemoPerson('Maya Thompson', 'Toronto');
        // The biryani story's first scene; the tasting is still to come
        $this->postScript([
            ['tanvir', 'making my nani\'s biryani for the first time today 🍚 she\'s watching on video call'],
            ['sofia', 'watching or judging? 😅'],
            ['tanvir', 'judging. she already said my onions are "too shy"'],
        ]);
        // Someone else's story doesn't come along
        $this->postScript([['maya', 'made pancakes for the first time']]);

        $this->send(self::ANN, 'how did the biryani turn out??', $tanvir);
        $this->wait(Bot::COME_ONLINE_SECONDS + Bot::DEMO_ANSWER_SECONDS);

        [$instructions, $chat] = TestBot::$asked[0];
        $this->assertSame('Ann: how did the biryani turn out??', $chat, 'the private chat stays private');
        $this->assertStringContainsString('Tanvir (you): judging. she already said my onions are "too shy"', $instructions);
        $this->assertStringContainsString('Not over yet', $instructions);
        $this->assertStringNotContainsString('not bad', $instructions);
        $this->assertStringNotContainsString('pancakes', $instructions);
    }

    public function testEachDemoPersonHasTheirOwnVoice(): void
    {
        $emily = $this->addDemoPerson('Emily Carter', 'Boise');
        $tanvir = $this->addDemoPerson('Tanvir Hasan', 'Dhaka');
        $this->send(self::ANN, '@Emily @Tanvir what are you cooking this week?');
        $this->wait(Bot::COME_ONLINE_SECONDS + Bot::DEMO_ANSWER_SECONDS);
        $this->wait(Bot::ROOM_COOLDOWN_SECONDS);

        $voices = require dirname(__DIR__) . '/database/demo-voices.php';
        $asked = array_column(TestBot::$asked, 0);
        $this->assertCount(2, $asked);
        $emilys = array_values(array_filter($asked, fn ($instructions) => str_contains($instructions, 'You are Emily')))[0];
        $tanvirs = array_values(array_filter($asked, fn ($instructions) => str_contains($instructions, 'You are Tanvir')))[0];
        $this->assertStringContainsString($voices['emily'], $emilys);
        $this->assertStringContainsString($voices['tanvir'], $tanvirs);
        $this->assertStringNotContainsString($voices['tanvir'], $emilys);
    }

    public function testThanksGotItClosesTheChat(): void
    {
        $tanvir = $this->addDemoPerson('Tanvir Hasan', 'Dhaka');
        foreach (['thanks, got it', 'ok cool, makes sense 👍', 'Not really.', "i'm good, thank you so much!"] as $i => $closing) {
            $this->send(self::ANN, $closing, $tanvir);
            $this->wait(Bot::COME_ONLINE_SECONDS + Bot::DEMO_ANSWER_SECONDS);
            $this->assertStringContainsString('done chatting for now', TestBot::$asked[$i][0], $closing);
        }

        foreach (['not good at all', 'thanks! but what did your nani say about the rice?'] as $i => $notClosing) {
            $this->send(self::ANN, $notClosing, $tanvir);
            $this->wait(Bot::COME_ONLINE_SECONDS + Bot::DEMO_ANSWER_SECONDS);
            $this->assertStringNotContainsString('done chatting', TestBot::$asked[4 + $i][0], $notClosing);
        }
    }

    public function testPrivateReplyTakesAboutAsLongAsTypingIt(): void
    {
        $tanvir = $this->addDemoPerson('Tanvir Hasan', 'Dhaka');
        TestBot::$typing = null;
        // 20 characters, 4 seconds to type
        $reply = 'slow and steady wins';
        TestBot::useReply($reply);

        $this->send(self::ANN, 'how is the biryani?', $tanvir);
        $this->wait(Bot::COME_ONLINE_SECONDS + Bot::DEMO_ANSWER_SECONDS);
        $this->assertSame([], $this->answers(), 'written, but still being typed');

        $typing = (int) ceil(mb_strlen(trim($reply)) / Bot::TYPING_CHARS_PER_SECOND);
        $this->wait($typing - 1);
        $this->assertSame([], $this->answers());

        $this->wait(1);
        $this->assertSame([[$tanvir, trim($reply)]], $this->answers(), 'posted once typed, and only once');
        $this->wait(Bot::MOST_TYPING_SECONDS);
        $this->assertCount(1, $this->answers());
    }

    public function testDemoPersonComesOnlineAMomentLaterAndStaysWhileTalking(): void
    {
        $wanjiru = $this->addDemoPerson('Wanjiru Kamau', 'Nairobi');

        $this->send(self::ANN, 'hi wanjiru', $wanjiru);
        $this->assertNotContains($wanjiru, TestBot::talkingDemoPeople(), 'not the moment the message is sent');

        $this->wait(Bot::COME_ONLINE_SECONDS);
        $this->assertContains($wanjiru, TestBot::talkingDemoPeople(), 'online a moment later');

        $this->wait(Bot::DEMO_ANSWER_SECONDS);
        $this->assertSame($wanjiru, $this->answers()[0][0] ?? null);

        $this->wait(Bot::CONVERSATION_MINUTES * 60 + 1);
        $this->assertNotContains($wanjiru, TestBot::talkingDemoPeople(), 'until the chat goes quiet');
    }

}

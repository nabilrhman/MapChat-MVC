<?php

namespace Tests\Models;

use App\Bot\Characters;
use App\Models\Chat;
use App\Models\DemoChat;
use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\TestDb;

/**
 * The demo clock, with its "now" fixed
 */
class TestDemoChat extends DemoChat
{
    public static int $demoNow = 0;

    /** Stamp lines on the demo clock rather than the real one (see the catch-up test) */
    public static bool $isStampedOnDemoClock = false;

    /** How many times the demo clock was read */
    public static int $clockReadings = 0;

    /** What the catch-up throws after its first change, or null to run as usual */
    public static ?\Throwable $failure = null;

    /**
     * When a line said at this demo time was seen, on the real clock
     */
    public static function seenAt(string $time): string
    {
        return static::realTime($time, static::now());
    }

    /**
     * A line as the room shows it, with its @mention
     */
    public static function addressed(string $body): string
    {
        return static::withAddressee($body);
    }

    /**
     * Catch up at the fixed demo time, without the lock
     */
    public static function catchUpNow(): void
    {
        static::catchUpLocked(TestDb::get());
    }

    /**
     * Midnight at the start of the demo day
     */
    public static function todayStart(): int
    {
        return static::dayStart(static::today());
    }

    /**
     * The fixed demo time
     */
    protected static function now(): int
    {
        static::$clockReadings++;

        return static::$demoNow;
    }

    /**
     * The demo time itself while $isStampedOnDemoClock, else the real time
     */
    protected static function realTime(string $time, int $demoNow): string
    {
        return static::$isStampedOnDemoClock ? $time : parent::realTime($time, $demoNow);
    }

    /**
     * The catch-up, or a line added and then $failure thrown
     */
    protected static function catchUpLocked(PDO $db): void
    {
        if (static::$failure === null) {
            parent::catchUpLocked($db);
            return;
        }

        $db->prepare('INSERT INTO chats (email, body) VALUES (?, ?)')->execute([Chat::BOT_EMAIL, 'half a catch-up']);
        throw static::$failure;
    }
}

/**
 * When the demo people were last seen, for "Recently here"
 */
class DemoChatTest extends TestCase
{
    protected function tearDown(): void
    {
        putenv('DEMO_SPEED');
        putenv('DEMO_AWAY');
        putenv('DEMO_HERE');
        TestDemoChat::$isStampedOnDemoClock = false;
        TestDemoChat::$failure = null;
    }

    public function testSomeoneDemoAwayNamesIsNeverInTheRoom(): void
    {
        // A demo person's first line, when they're in the room
        $demoLines = array_filter(
            TestDemoChat::schedule(TestDemoChat::today()),
            fn (array $line): bool => str_ends_with($line['email'], Characters::DEMO_DOMAIN),
        );
        $firstLine = reset($demoLines);
        TestDemoChat::$demoNow = strtotime($firstLine['time'] . ' UTC');
        $this->assertContains($firstLine['email'], TestDemoChat::presentNow());

        putenv('DEMO_AWAY=' . strtok($firstLine['email'], '@'));

        $this->assertNotContains($firstLine['email'], TestDemoChat::presentNow());
    }

    public function testSomeoneDemoHereNamesIsAlwaysInTheRoom(): void
    {
        // Two days past today's script, so nobody in it is around
        TestDemoChat::$demoNow = strtotime(TestDemoChat::today() . ' UTC') + 2 * 86400;
        $mayaEmail = 'maya' . Characters::DEMO_DOMAIN;
        $this->assertNotContains($mayaEmail, TestDemoChat::presentNow());

        putenv('DEMO_HERE=maya');

        $this->assertContains($mayaEmail, TestDemoChat::presentNow());
    }

    public function testRealTimeIsTheDemoTimeAtNormalSpeed(): void
    {
        // No pause: the demo clock is the real one
        TestDemoChat::$demoNow = time();
        $line = gmdate('Y-m-d H:i:s', time() - 600);

        $this->assertSame($line, TestDemoChat::seenAt($line));
    }

    public function testFastDayIsSeenOnTheRealClock(): void
    {
        putenv('DEMO_SPEED=60');
        // The demo clock is hours ahead; a line 30 demo minutes ago was 30 real seconds ago
        TestDemoChat::$demoNow = time() + 4 * 3600;
        $line = gmdate('Y-m-d H:i:s', TestDemoChat::$demoNow - 30 * 60);

        $seen = strtotime(TestDemoChat::seenAt($line) . ' UTC');

        $this->assertEqualsWithDelta(time() - 30, $seen, 2);
    }

    public function testLinesThatNameSomeoneTagThem(): void
    {
        $db = TestDb::get();
        $db->beginTransaction();
        try {
            foreach (['Rafael Costa' => 'rafael', 'Jae-won Park' => 'jaewon'] as $name => $key) {
                TestDb::createUser($name, "$key@demo.mapchat.local");
            }

            $this->assertSame('@Rafael how was it?', TestDemoChat::addressed('rafael how was it?'));
            $this->assertSame('@Rafael, important question', TestDemoChat::addressed('rafael, important question'));
            $this->assertSame('@Jae-won is the kimchi ready?', TestDemoChat::addressed('jaewon is the kimchi ready?'));
            $this->assertSame('tried surfing with rafael', TestDemoChat::addressed('tried surfing with rafael'));
            $this->assertSame('rafaela says hi', TestDemoChat::addressed('rafaela says hi'));
        } finally {
            $db->rollBack();
        }
    }

    /**
     * Today's demo bodies in the chat, in the order they went out
     */
    private function postedToday(): array
    {
        $stmt = TestDb::get()->prepare("SELECT body FROM chats WHERE email LIKE '%@demo.mapchat.local' AND timestamp >= ? ORDER BY id");
        $stmt->execute([gmdate('Y-m-d H:i:s', TestDemoChat::todayStart())]);

        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    /**
     * Run a catch-up with the demo clock at the given line of today's plan
     */
    private function catchUpTo(array $schedule, int $line): void
    {
        TestDemoChat::$demoNow = strtotime($schedule[$line]['time'] . ' UTC');
        TestDemoChat::catchUpNow();
    }

    public function testACatchUpLeavesSomeoneDemoAwayNamesUnseen(): void
    {
        TestDemoChat::$isStampedOnDemoClock = true;

        $db = TestDb::get();
        $db->beginTransaction();
        try {
            $schedule = TestDemoChat::schedule(TestDemoChat::today());
            $demoEmails = array_values(array_unique(array_filter(
                array_column(array_slice($schedule, 0, 21), 'email'),
                fn (string $email): bool => str_ends_with($email, Characters::DEMO_DOMAIN),
            )));
            foreach ($demoEmails as $email) {
                TestDb::createUser(ucfirst(strtok($email, '@')), $email);
                $db->prepare("INSERT INTO userinfo (email, ip) VALUES (?, '')")->execute([$email]);
            }
            [$awayEmail, $hereEmail] = $demoEmails;
            putenv('DEMO_AWAY=' . strtok($awayEmail, '@'));

            $this->catchUpTo(TestDemoChat::schedule(TestDemoChat::today()), 20);

            $seenAt = $db->prepare('SELECT online_at FROM userinfo WHERE email = ?');
            $seenAt->execute([$awayEmail]);
            $this->assertNull($seenAt->fetchColumn());
            $seenAt->execute([$hereEmail]);
            $this->assertNotNull($seenAt->fetchColumn());
        } finally {
            $db->rollBack();
        }
    }

    public function testALineTranslatedInTheScriptIsPostedWithWhatWasTyped(): void
    {
        TestDemoChat::$isStampedOnDemoClock = true;

        $db = TestDb::get();
        $db->beginTransaction();
        try {
            $schedule = TestDemoChat::schedule(TestDemoChat::today());
            foreach (array_unique(array_column($schedule, 'email')) as $email) {
                TestDb::createUser(ucfirst(strtok($email, '@')), $email);
            }
            $schedule = TestDemoChat::schedule(TestDemoChat::today());
            $translatedLine = array_key_first(array_filter(array_column($schedule, 'original')));

            $this->catchUpTo($schedule, $translatedLine);

            $stmt = $db->prepare('SELECT original_body FROM chats WHERE email = ? AND body = ?');
            $stmt->execute([$schedule[$translatedLine]['email'], $schedule[$translatedLine]['body']]);
            $this->assertSame($schedule[$translatedLine]['original'], $stmt->fetchColumn());
        } finally {
            $db->rollBack();
        }
    }

    public function testCatchUpGoesOnFromWhereTheStoryIs(): void
    {
        // The demo clock is moved to a line of today's plan, which is ahead
        // of the real clock early in the demo day. Stamped on the real clock,
        // the lines would land in yesterday and not count as posted today
        TestDemoChat::$isStampedOnDemoClock = true;

        $db = TestDb::get();
        $db->beginTransaction();
        try {
            $schedule = TestDemoChat::schedule(TestDemoChat::today());
            foreach (array_unique(array_column($schedule, 'email')) as $email) {
                TestDb::createUser(ucfirst(strtok($email, '@')), $email);
            }
            $schedule = TestDemoChat::schedule(TestDemoChat::today()); // now with the people's names, for @tags
            $bodies = array_column($schedule, 'body');

            // The same words from an earlier day don't count as said today
            $db->prepare("INSERT INTO chats (email, body, timestamp) VALUES (?, ?, ?)")
                ->execute([$schedule[0]['email'], $schedule[0]['body'], gmdate('Y-m-d H:i:s', TestDemoChat::todayStart() - 3600)]);

            $this->catchUpTo($schedule, 20);
            $this->assertSame(array_slice($bodies, 0, 21), $this->postedToday());

            $this->catchUpTo($schedule, 20);
            $this->assertSame(array_slice($bodies, 0, 21), $this->postedToday(), 'nothing twice');

            // However late the story got, it goes on from the next line
            $this->catchUpTo($schedule, 30);
            $this->assertSame(array_slice($bodies, 0, 31), $this->postedToday());
        } finally {
            $db->rollBack();
        }
    }

    public function testALiveAnswerStaysPutAfterItsJobIsGone(): void
    {
        TestDemoChat::$isStampedOnDemoClock = true;

        $db = TestDb::get();
        $db->beginTransaction();
        try {
            $schedule = TestDemoChat::schedule(TestDemoChat::today());
            foreach (array_unique(array_column($schedule, 'email')) as $email) {
                TestDb::createUser(ucfirst(strtok($email, '@')), $email);
            }
            $schedule = TestDemoChat::schedule(TestDemoChat::today());

            // A greeting from yesterday and a demo person's answer today, with
            // no job left (it went with the guest they answered)
            $addAnswer = $db->prepare('INSERT INTO chats (email, body, timestamp, is_live_answer) VALUES (?, ?, ?, TRUE)');
            $addAnswer->execute([Chat::BOT_EMAIL, '@Guest welcome!', gmdate('Y-m-d H:i:s', TestDemoChat::todayStart() - 3600)]);
            $greetingId = (int) $db->lastInsertId();
            $addAnswer->execute([$schedule[0]['email'], '@Guest nice to meet you', $schedule[0]['time']]);
            $answerId = (int) $db->lastInsertId();

            $this->catchUpTo($schedule, 20);
            $this->catchUpTo($schedule, 21);

            $stillThere = $db->prepare('SELECT COUNT(*) FROM chats WHERE id IN (?, ?)');
            $stillThere->execute([$greetingId, $answerId]);
            $this->assertSame(2, (int) $stillThere->fetchColumn(), 'neither moved nor cleared as a script line');
            $this->assertSame(array_column(array_slice($schedule, 0, 22), 'body'),
                array_values(array_diff($this->postedToday(), ['@Guest nice to meet you'])), 'the script goes on');
        } finally {
            $db->rollBack();
        }
    }

    public function testACatchUpReadsTheDemoClockOnce(): void
    {
        TestDemoChat::$isStampedOnDemoClock = true;

        $db = TestDb::get();
        $db->beginTransaction();
        try {
            $schedule = TestDemoChat::schedule(TestDemoChat::today());
            foreach (array_unique(array_column($schedule, 'email')) as $email) {
                TestDb::createUser(ucfirst(strtok($email, '@')), $email);
            }
            $schedule = TestDemoChat::schedule(TestDemoChat::today());
            TestDemoChat::$clockReadings = 0;

            $this->catchUpTo($schedule, 20);

            $this->assertSame(1, TestDemoChat::$clockReadings);
        } finally {
            $db->rollBack();
        }
    }

    public function testACatchUpThatBreaksIsUndoneAndReported(): void
    {
        $db = TestDb::get();
        TestDemoChat::$failure = new \RuntimeException('a bad line in demo-chat.php');

        try {
            TestDemoChat::catchUp();
            $this->fail('the error is passed on');
        } catch (\RuntimeException $e) {
            $this->assertSame('a bad line in demo-chat.php', $e->getMessage());
        }

        $this->assertFalse($db->inTransaction());
        $this->assertSame(0, (int) $db->query("SELECT COUNT(*) FROM chats WHERE body = 'half a catch-up'")->fetchColumn());
        $this->assertSame(1, (int) $db->query("SELECT IS_FREE_LOCK('demo_catch_up')")->fetchColumn(), 'the lock is let go');
    }
}

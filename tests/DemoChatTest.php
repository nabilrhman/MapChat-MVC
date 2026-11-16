<?php

namespace Tests;

use App\Models\DemoChat;
use PHPUnit\Framework\TestCase;

/**
 * The demo clock, with its "now" fixed
 */
class TestDemoChat extends DemoChat
{
    public static int $demoNow = 0;

    /** Stamp lines on the demo clock rather than the real one (see the catch-up test) */
    public static bool $isStampedOnDemoClock = false;

    /**
     * When a line said at this demo time was seen, on the real clock
     */
    public static function seenAt(string $time): string
    {
        return static::realTime($time);
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
        return static::$demoNow;
    }

    /**
     * The demo time itself while $isStampedOnDemoClock, else the real time
     */
    protected static function realTime(string $time): string
    {
        return static::$isStampedOnDemoClock ? $time : parent::realTime($time);
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
        TestDemoChat::$isStampedOnDemoClock = false;
    }

    public function testRealTimeIsTheDemoTimeAtNormalSpeed()
    {
        // No pause: the demo clock is the real one
        TestDemoChat::$demoNow = time();
        $line = gmdate('Y-m-d H:i:s', time() - 600);

        $this->assertSame($line, TestDemoChat::seenAt($line));
    }

    public function testFastDayIsSeenOnTheRealClock()
    {
        putenv('DEMO_SPEED=60');
        // The demo clock is hours ahead; a line 30 demo minutes ago was 30 real seconds ago
        TestDemoChat::$demoNow = time() + 4 * 3600;
        $line = gmdate('Y-m-d H:i:s', TestDemoChat::$demoNow - 30 * 60);

        $seen = strtotime(TestDemoChat::seenAt($line) . ' UTC');

        $this->assertEqualsWithDelta(time() - 30, $seen, 2);
    }

    public function testLinesThatNameSomeoneTagThem()
    {
        $db = TestDb::get();
        $db->beginTransaction();
        try {
            foreach (['Rafael Costa' => 'rafael', 'Jae-won Park' => 'jaewon'] as $name => $key) {
                $db->prepare("INSERT INTO users (name, email, password_hash, is_active) VALUES (?, ?, '!', TRUE)")
                    ->execute([$name, "$key@demo.mapchat.local"]);
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

        return $stmt->fetchAll(\PDO::FETCH_COLUMN);
    }

    /**
     * Run a catch-up with the demo clock at the given line of today's plan
     */
    private function catchUpTo(array $schedule, int $line): void
    {
        TestDemoChat::$demoNow = strtotime($schedule[$line][0] . ' UTC');
        TestDemoChat::catchUpNow();
    }

    public function testCatchUpGoesOnFromWhereTheStoryIs()
    {
        // The demo clock is moved to a line of today's plan, which is ahead
        // of the real clock early in the demo day. Stamped on the real clock,
        // the lines would land in yesterday and not count as posted today
        TestDemoChat::$isStampedOnDemoClock = true;

        $db = TestDb::get();
        $db->beginTransaction();
        try {
            $schedule = TestDemoChat::schedule(TestDemoChat::today());
            foreach (array_unique(array_column($schedule, 1)) as $email) {
                $db->prepare("INSERT INTO users (name, email, password_hash, is_active) VALUES (?, ?, '!', TRUE)")
                    ->execute([ucfirst(strtok($email, '@')), $email]);
            }
            $schedule = TestDemoChat::schedule(TestDemoChat::today()); // now with the people's names, for @tags
            $bodies = array_column($schedule, 2);

            // The same words from an earlier day don't count as said today
            $db->prepare("INSERT INTO chats (email, body, timestamp) VALUES (?, ?, ?)")
                ->execute([$schedule[0][1], $schedule[0][2], gmdate('Y-m-d H:i:s', TestDemoChat::todayStart() - 3600)]);

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
}

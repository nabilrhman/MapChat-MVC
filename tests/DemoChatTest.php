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

    public static function seenAt(string $time): string
    {
        return static::realTime($time);
    }

    public static function addressed(string $body): string
    {
        return static::withAddressee($body);
    }

    protected static function now()
    {
        return static::$demoNow;
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
    }

    public function testRealTimeIsTheDemoTimeAtNormalSpeed()
    {
        $this->assertSame('2026-11-16 04:30:00', TestDemoChat::seenAt('2026-11-16 04:30:00'));
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
}

<?php

namespace Tests\ChatSocket;

use App\ChatSocket\NewMessageReader;
use App\Models\Chat;
use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\TestDb;

/**
 * A reader with a clock the tests move
 */
class TestNewMessageReader extends NewMessageReader
{
    public float $later = 0;

    /**
     * The real time, moved on by $later
     */
    protected function now(): float
    {
        return microtime(true) + $this->later;
    }
}

/**
 * Reading new messages in id order without skipping one that took an
 * earlier id but committed later, as the chat socket and the chat worker's
 * Web Push both do. Each test runs in a transaction that is rolled back
 */
class NewMessageReaderTest extends TestCase
{
    private PDO $db;

    /** The newest id before the test, which the test's messages come after */
    private int $start;

    protected function setUp(): void
    {
        $this->db = TestDb::get();
        $this->db->beginTransaction();
        // Clear of any id another test's rolled-back insert took
        $this->start = Chat::getNewestId() + 1000;
    }

    protected function tearDown(): void
    {
        $this->db->rollBack();
    }

    /**
     * Save a message with this id, after the test's start
     */
    private function saveWithId(int $offset): int
    {
        $id = $this->start + $offset;
        $this->db->prepare('INSERT INTO chats (id, email, body) VALUES (?, ?, ?)')
            ->execute([$id, Chat::BOT_EMAIL, "message $offset"]);

        return $id;
    }

    public function testReadsNewIdsInOrder(): void
    {
        $reader = new TestNewMessageReader($this->start);
        $ids = [$this->saveWithId(1), $this->saveWithId(2), $this->saveWithId(3)];

        $this->assertSame($ids, $reader->readNewIds(50));
        $this->assertSame([], $reader->readNewIds(50));
        $this->assertSame($ids[2], $reader->getLastReadId());
    }

    public function testWaitsAtAMissingIdForTheMessageThatTookIt(): void
    {
        $reader = new TestNewMessageReader($this->start);
        $first = $this->saveWithId(1);
        $third = $this->saveWithId(3);

        $this->assertSame([$first], $reader->readNewIds(50));

        // The send that took the missing id commits a moment later
        $reader->later = NewMessageReader::GAP_SECONDS / 2;
        $second = $this->saveWithId(2);

        $this->assertSame([$second, $third], $reader->readNewIds(50));
    }

    public function testReadsOnPastAnIdThatNeverComes(): void
    {
        $reader = new TestNewMessageReader($this->start);
        $first = $this->saveWithId(1);
        $third = $this->saveWithId(3);

        $this->assertSame([$first], $reader->readNewIds(50));

        $reader->later = NewMessageReader::GAP_SECONDS / 2;
        $this->assertSame([], $reader->readNewIds(50), 'still waiting');

        $reader->later = NewMessageReader::GAP_SECONDS + 0.1;
        $this->assertSame([$third], $reader->readNewIds(50));
    }

    public function testStopsAtTheLimit(): void
    {
        $reader = new TestNewMessageReader($this->start);
        $ids = [$this->saveWithId(1), $this->saveWithId(2), $this->saveWithId(3)];

        $this->assertSame(array_slice($ids, 0, 2), $reader->readNewIds(2));
        $this->assertSame([$ids[2]], $reader->readNewIds(2));
    }
}

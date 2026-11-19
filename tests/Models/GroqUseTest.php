<?php

namespace Tests\Models;

use App\Bot\GroqUseKind;
use App\Models\GroqUse;
use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\TestDb;

/**
 * Each person's turns a minute at translating and voice typing. Each test
 * runs in a transaction that is rolled back
 */
class GroqUseTest extends TestCase
{
    const LIMIT = 3;

    private PDO $db;

    private int $userId;

    /**
     * Start each test with Pat, who hasn't asked Groq for anything
     */
    protected function setUp(): void
    {
        $this->db = TestDb::get();
        $this->db->beginTransaction();
        $this->userId = TestDb::createUser('Pat', 'limit@test.local');
    }

    /**
     * Undo the test's rows
     */
    protected function tearDown(): void
    {
        $this->db->rollBack();
    }

    public function testTurnsRunOutAtTheLimit(): void
    {
        for ($i = 0; $i < self::LIMIT; $i++) {
            $this->assertTrue(GroqUse::claim($this->userId, GroqUseKind::Translate, self::LIMIT));
        }

        $this->assertFalse(GroqUse::claim($this->userId, GroqUseKind::Translate, self::LIMIT));
        $this->assertTrue(GroqUse::isUsedUp($this->userId, GroqUseKind::Translate, self::LIMIT));
    }

    public function testVoiceTypingHasALimitOfItsOwn(): void
    {
        for ($i = 0; $i < self::LIMIT; $i++) {
            GroqUse::claim($this->userId, GroqUseKind::Translate, self::LIMIT);
        }

        $this->assertTrue(GroqUse::claim($this->userId, GroqUseKind::Transcribe, self::LIMIT));
    }

    public function testOnlyTheLastMinutesTurnsCount(): void
    {
        $this->db->prepare("INSERT INTO groq_uses (user_id, kind, used_at) VALUES (?, 'translate', NOW() - INTERVAL 61 SECOND)")
            ->execute([$this->userId]);
        $this->db->prepare("INSERT INTO groq_uses (user_id, kind, used_at) VALUES (?, 'translate', NOW() - INTERVAL 90 SECOND)")
            ->execute([$this->userId]);

        $this->assertFalse(GroqUse::isUsedUp($this->userId, GroqUseKind::Translate, 2));
    }

    public function testTurnsAreCountedPerPersonNotPerSession(): void
    {
        GroqUse::claim($this->userId, GroqUseKind::Translate, 1);

        // A fresh session, as a remembered login gets, is the same person
        $_SESSION = [];
        $this->assertFalse(GroqUse::claim($this->userId, GroqUseKind::Translate, 1));

        $otherId = TestDb::createUser('Sam', 'other@test.local');
        $this->assertTrue(GroqUse::claim($otherId, GroqUseKind::Translate, 1));
    }
}

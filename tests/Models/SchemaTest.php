<?php

namespace Tests\Models;

use App\Models\Chat;
use App\Models\Schema;
use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\TestDb;

/**
 * Bringing an older database up to date. Changing a table commits at once,
 * so each test puts back what it took away
 */
class SchemaTest extends TestCase
{
    const GUEST = 'schema-guest@test.local';
    const GREETING = '@Guest welcome!';

    private PDO $db;

    /** The test database, as init.sql made it */
    protected function setUp(): void
    {
        $this->db = TestDb::get();
    }

    /** Whatever a test took away is added back, and its rows are gone */
    protected function tearDown(): void
    {
        Schema::upgrade();
        $this->db->prepare('DELETE FROM users WHERE email = ?')->execute([self::GUEST]);
        $this->db->prepare('DELETE FROM chats WHERE email = ? AND body = ?')->execute([Chat::BOT_EMAIL, self::GREETING]);
    }

    /**
     * Whether a table has an index of this name
     */
    private function hasIndex(string $table, string $index): bool
    {
        $stmt = $this->db->prepare('SELECT COUNT(*) FROM information_schema.STATISTICS
                                    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?');
        $stmt->execute([$table, $index]);

        return $stmt->fetchColumn() > 0;
    }

    /**
     * Whether a table has a foreign key of this name
     */
    private function hasForeignKey(string $table, string $constraint): bool
    {
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
                                    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND CONSTRAINT_NAME = ?
                                      AND CONSTRAINT_TYPE = 'FOREIGN KEY'");
        $stmt->execute([$table, $constraint]);

        return $stmt->fetchColumn() > 0;
    }

    public function testAnUpToDateDatabaseGetsNothingAdded(): void
    {
        Schema::upgrade();

        $this->assertSame([], Schema::upgrade());
    }

    public function testAMissingUniqueKeyIsAddedThoughItsColumnIsThere(): void
    {
        $this->db->exec('ALTER TABLE users DROP INDEX nickname');

        $this->assertContains('users index nickname', Schema::upgrade());
        $this->assertTrue($this->hasIndex('users', 'nickname'));
    }

    public function testAMissingForeignKeyIsAddedThoughItsColumnIsThere(): void
    {
        $this->db->exec('ALTER TABLE chats DROP FOREIGN KEY fk_m_to_email');

        $this->assertSame(['chats constraint fk_m_to_email'], Schema::upgrade());
        $this->assertTrue($this->hasForeignKey('chats', 'fk_m_to_email'));
    }

    public function testLiveAnswersAreMarkedWhenTheirColumnIsAdded(): void
    {
        TestDb::createUser('Guest', self::GUEST, isGuest: true);
        $this->db->prepare('INSERT INTO chats (email, body) VALUES (?, ?)')->execute([Chat::BOT_EMAIL, self::GREETING]);
        $greetingId = (int) $this->db->lastInsertId();
        $this->db->prepare("INSERT INTO mapchat_jobs (kind, email, dedupe_key, due_at, done_at, posted_id)
                            VALUES ('greet', ?, ?, NOW(), NOW(), ?)")
            ->execute([self::GUEST, 'schema-test ' . $greetingId, $greetingId]);

        $this->db->exec('ALTER TABLE chats DROP COLUMN is_live_answer');
        $this->assertSame(['chats.is_live_answer'], Schema::upgrade());

        $stmt = $this->db->prepare('SELECT is_live_answer FROM chats WHERE id = ?');
        $stmt->execute([$greetingId]);
        $this->assertSame(1, (int) $stmt->fetchColumn());
    }
}

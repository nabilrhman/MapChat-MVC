<?php

namespace Tests\Controllers;

use App\Models\Chat;
use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\DrivesTheSite;
use Tests\Support\TestDb;

/**
 * The chat's HTTP fallback (Room), over real HTTP requests: what a poll
 * leaves to the chat worker, and requests no page of ours sends. The site
 * has a Groq key, but no request here gets as far as Groq
 */
class RoomTest extends TestCase
{
    use DrivesTheSite;

    const EMAIL = 'room@test.local';
    const PASSWORD = 'secret123';

    private PDO $db;

    public static function setUpBeforeClass(): void
    {
        self::startSite(['GROQ_API_KEY' => 'test-key-never-sent']);
    }

    public static function tearDownAfterClass(): void
    {
        self::stopSite();
    }

    protected function setUp(): void
    {
        // The server is another process, so these rows are committed, not
        // rolled back; tearDown deletes them
        $this->db = TestDb::get();
        TestDb::createUser('Pat', self::EMAIL, self::PASSWORD);
        $this->request('POST', '/login/create', ['email' => self::EMAIL, 'password' => self::PASSWORD]);
    }

    protected function tearDown(): void
    {
        $this->db->prepare('DELETE FROM chats WHERE email = ?')->execute([self::EMAIL]);
        $this->db->prepare('DELETE FROM userinfo WHERE email = ?')->execute([self::EMAIL]);
        $this->db->prepare('DELETE FROM users WHERE email = ?')->execute([self::EMAIL]);
    }

    public function testAPollLeavesMapChatsDueRepliesToTheChatWorker(): void
    {
        $this->db->prepare("INSERT INTO mapchat_jobs (kind, email, dedupe_key, due_at) VALUES ('greet', ?, 'room-test-greet', NOW())")
            ->execute([self::EMAIL]);

        $response = $this->request('POST', '/app/get', ['after' => 0]);

        $stmt = $this->db->prepare('SELECT done_at FROM mapchat_jobs WHERE email = ?');
        $stmt->execute([self::EMAIL]);
        $this->assertSame(200, $response['status']);
        $this->assertNull($stmt->fetchColumn());
    }

    public function testAListInPlaceOfAMessageIsIgnoredNotAnError(): void
    {
        $response = $this->request('POST', '/app/send', ['body' => ['hello']]);

        $stmt = $this->db->prepare('SELECT COUNT(*) FROM chats WHERE email = ?');
        $stmt->execute([self::EMAIL]);
        $this->assertSame(200, $response['status']);
        $this->assertSame(0, (int) $stmt->fetchColumn());
    }

    public function testAListInPlaceOfAClientIdOrTheOriginalSendsTheMessageWithout(): void
    {
        $response = $this->request('POST', '/app/send', ['body' => 'hello', 'client_id' => ['a'], 'original' => ['b']]);

        $stmt = $this->db->prepare('SELECT COUNT(*) FROM chats WHERE email = ?');
        $stmt->execute([self::EMAIL]);
        $this->assertSame(200, $response['status']);
        $this->assertSame(1, (int) $stmt->fetchColumn());
    }

    public function testARefusedRecordingUsesNoneOfTheLimit(): void
    {
        for ($i = 0; $i < Chat::USER_PER_MINUTE + 1; $i++) {
            $response = $this->request('POST', '/app/transcribe');
        }

        $this->assertSame(422, $response['status']);
    }
}

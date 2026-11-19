<?php

namespace Tests\Controllers;

use App\Models\Chat;
use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\DrivesTheSite;
use Tests\Support\TestDb;

/**
 * The message box's translations (Room::translateAction), over real HTTP
 * requests. The site has a Groq key, but no request here gets as far as
 * Groq: they're refused before it's asked
 */
class TranslateTest extends TestCase
{
    use DrivesTheSite;

    const EMAIL = 'translate@test.local';
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
        $this->db->prepare('DELETE FROM userinfo WHERE email = ?')->execute([self::EMAIL]);
        $this->db->prepare('DELETE FROM users WHERE email = ?')->execute([self::EMAIL]);
    }

    public function testATranslationIntoNoKnownLanguageIsRefused(): void
    {
        $response = $this->request('POST', '/app/translate', ['text' => 'hello', 'from' => '', 'to' => 'xx']);

        $this->assertSame(422, $response['status']);
    }

    public function testARefusedTranslationUsesNoneOfTheLimit(): void
    {
        for ($i = 0; $i < Chat::USER_PER_MINUTE + 1; $i++) {
            $response = $this->request('POST', '/app/translate', ['text' => '', 'to' => 'en']);
        }

        $this->assertSame(422, $response['status']);
    }
}


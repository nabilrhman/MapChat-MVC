<?php

namespace Tests\SignIn;

use App\Models\User;
use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\TestDb;

/**
 * Emailing a password reset link: a failed send says so, but only for an
 * account that exists, so the answer never tells which emails have one;
 * each test runs in a transaction that is rolled back
 */
class PasswordResetTest extends TestCase
{
    const ANN = 'ann@test.local';

    private PDO $db;
    private string|false $dsnBefore;

    protected function setUp(): void
    {
        $this->db = TestDb::get();
        $this->db->beginTransaction();
        TestDb::createUser('Ann', self::ANN);

        $this->dsnBefore = getenv('MAILER_DSN');
    }

    protected function tearDown(): void
    {
        $this->dsnBefore === false ? putenv('MAILER_DSN') : putenv('MAILER_DSN=' . $this->dsnBefore);
        $this->db->rollBack();
    }

    /**
     * Ask for a reset link with email sent through this DSN, keeping its
     * logged failure out of the test's output
     */
    private function requestReset(string $dsn, string $email): bool
    {
        putenv('MAILER_DSN=' . $dsn);
        $logBefore = ini_set('error_log', '/dev/null');

        try {
            return User::sendPasswordReset($email);
        } finally {
            ini_set('error_log', (string) $logBefore);
        }
    }

    public function testASentEmailIsAnsweredAsSent(): void
    {
        $this->assertTrue($this->requestReset('null://null', self::ANN));
        $this->assertNotNull(User::findByEmail(self::ANN)->password_reset_hash);
    }

    public function testAFailedSendIsAnsweredAsFailed(): void
    {
        $this->assertFalse($this->requestReset('nope://user:pass@default', self::ANN));
    }

    public function testAnUnknownEmailIsAnsweredLikeASentOne(): void
    {
        $this->assertTrue($this->requestReset('nope://user:pass@default', 'nobody@test.local'));
    }
}

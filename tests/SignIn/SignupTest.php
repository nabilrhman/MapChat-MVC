<?php

namespace Tests\SignIn;

use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\DrivesTheSite;
use Tests\Support\TestDb;

/**
 * Signing up on a site that sends email, over real HTTP requests: the
 * account waits for its activation link. Mail goes to Symfony's null
 * transport, which takes it and sends nothing
 */
class SignupTest extends TestCase
{
    use DrivesTheSite;

    const EMAIL = 'signup@test.local';

    private PDO $db;

    public static function setUpBeforeClass(): void
    {
        self::startSite(['MAILER_DSN' => 'null://null']);
    }

    public static function tearDownAfterClass(): void
    {
        self::stopSite();
    }

    protected function setUp(): void
    {
        $this->db = TestDb::get();
    }

    protected function tearDown(): void
    {
        $this->db->prepare('DELETE FROM users WHERE email = ?')->execute([self::EMAIL]);
    }

    /**
     * The account's row as saved, or false when there is none
     */
    private function account(): array|false
    {
        $stmt = $this->db->prepare('SELECT name, is_active, activation_hash FROM users WHERE email = ?');
        $stmt->execute([self::EMAIL]);

        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function testSigningUpMakesAnAccountThatWaitsForTheEmailedLink(): void
    {
        $response = $this->request('POST', '/signup/create', ['name' => 'Nia Roe', 'email' => self::EMAIL, 'password' => 'secret123']);

        $this->assertRedirect('/signup/success', $response);
        $this->assertRedirect('/login', $this->request('GET', '/signup/success'));
        $this->assertStringContainsString('Check your email to activate it.', $this->request('GET', '/login')['body']);

        $account = $this->account();
        $this->assertSame('Nia Roe', $account['name']);
        $this->assertSame(0, (int) $account['is_active']);
        $this->assertNotEmpty($account['activation_hash']);
    }

    public function testTheAccountCantLogInBeforeItsActivated(): void
    {
        $this->request('POST', '/signup/create', ['name' => 'Nia Roe', 'email' => self::EMAIL, 'password' => 'secret123']);

        $response = $this->request('POST', '/login/create', ['email' => self::EMAIL, 'password' => 'secret123']);

        $this->assertStringContainsString('Activate your account first.', $response['body']);
    }

    public function testSigningUpAgainWithTheSameEmailIsRefused(): void
    {
        $this->request('POST', '/signup/create', ['name' => 'Nia Roe', 'email' => self::EMAIL, 'password' => 'secret123']);

        $response = $this->request('POST', '/signup/create', ['name' => 'Someone Else', 'email' => self::EMAIL, 'password' => 'other1234']);

        $this->assertSame(200, $response['status']);
        $this->assertMatchesRegularExpression('/id="email-input-error">[^<]+</', $response['body']);
        $this->assertSame('Nia Roe', $this->account()['name']);
    }

    public function testASignUpWithProblemsMakesNoAccount(): void
    {
        $response = $this->request('POST', '/signup/create', ['name' => '', 'email' => self::EMAIL, 'password' => 'abc']);

        $this->assertSame(200, $response['status']);
        $this->assertMatchesRegularExpression('/id="password-input-error">[^<]+</', $response['body']);
        $this->assertFalse($this->account());
    }
}

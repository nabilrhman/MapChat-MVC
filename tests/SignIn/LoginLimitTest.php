<?php

namespace Tests\SignIn;

use App\Models\User;
use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\TestDb;

/**
 * What a password login lets in: wrong passwords stop being tried after
 * MAX_FAILED_LOGINS for a while, and nobody takes an email at MapChat's own
 * domain. Each test runs in a transaction that is rolled back
 */
class LoginLimitTest extends TestCase
{
    const EMAIL = 'limit@test.local';
    const PASSWORD = 'secret123';

    private PDO $db;

    protected function setUp(): void
    {
        $this->db = TestDb::get();
        $this->db->beginTransaction();
        TestDb::createUser('Ann', self::EMAIL, self::PASSWORD);
    }

    protected function tearDown(): void
    {
        $this->db->rollBack();
    }

    /**
     * Try a wrong password this many times
     */
    private function tryWrongPasswords(int $times): void
    {
        for ($i = 0; $i < $times; $i++) {
            $this->assertNull(User::authenticate(self::EMAIL, 'wrong123'));
        }
    }

    public function testTooManyWrongPasswordsStopEvenTheRightOne(): void
    {
        $this->tryWrongPasswords(User::MAX_FAILED_LOGINS - 1);
        $this->assertFalse(User::findByEmail(self::EMAIL)->isLoginLocked());

        $this->tryWrongPasswords(1);

        $this->assertTrue(User::findByEmail(self::EMAIL)->isLoginLocked());
        $this->assertNull(User::authenticate(self::EMAIL, self::PASSWORD));
    }

    public function testTheRightPasswordStartsTheCountAgain(): void
    {
        $this->tryWrongPasswords(User::MAX_FAILED_LOGINS - 1);

        $this->assertNotNull(User::authenticate(self::EMAIL, self::PASSWORD));
        $this->tryWrongPasswords(User::MAX_FAILED_LOGINS - 1);

        $this->assertNotNull(User::authenticate(self::EMAIL, self::PASSWORD));
    }

    public function testWrongPasswordsStopCountingOnceTheirMinutesAreUp(): void
    {
        $this->tryWrongPasswords(User::MAX_FAILED_LOGINS);
        $this->db->prepare('UPDATE users SET failed_logins_since = NOW() - INTERVAL ? MINUTE WHERE email = ?')
            ->execute([User::FAILED_LOGIN_MINUTES, self::EMAIL]);

        $this->assertNotNull(User::authenticate(self::EMAIL, self::PASSWORD));
    }

    public function testAPasswordResetLetsTheOwnerInAtOnce(): void
    {
        $this->tryWrongPasswords(User::MAX_FAILED_LOGINS);

        $this->assertTrue(User::findByEmail(self::EMAIL)->resetPassword('newpass123'));

        $this->assertNotNull(User::authenticate(self::EMAIL, 'newpass123'));
    }

    public function testEmailsAtMapChatsOwnDomainAreRefused(): void
    {
        foreach (['bot@mapchat.local', 'mia@demo.mapchat.local', 'Mia@Demo.MapChat.Local', 'x@guest.mapchat.local'] as $email) {
            $user = new User(['name' => 'Ann', 'email' => $email, 'password' => self::PASSWORD]);
            $user->validate();

            $this->assertArrayHasKey('email', $user->errors, $email);
        }

        $user = new User(['name' => 'Ann', 'email' => 'ann@notmapchat.local', 'password' => self::PASSWORD]);
        $user->validate();
        $this->assertArrayNotHasKey('email', $user->errors);
    }

    public function testAGuestKeepsTheirPlaceholderEmailWhenSavingTheirName(): void
    {
        $guest = User::createGuest();

        $isSaved = $guest->updateProfile(['name' => 'Guest', 'nickname' => '', 'email' => $guest->email, 'password' => '']);

        $this->assertTrue($isSaved);
        $this->assertNull(User::findByID($guest->id)->activation_hash);
    }
}

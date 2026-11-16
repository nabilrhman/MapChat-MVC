<?php

namespace Tests;

use App\Models\User;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Setting a nickname on the profile. Each test runs in a transaction that
 * is rolled back
 */
class NicknameTest extends TestCase
{
    private PDO $db;

    protected function setUp(): void
    {
        $this->db = TestDb::get();
        $this->db->beginTransaction();

        foreach (['ann@test.local' => 'Ann Lee', 'bob@test.local' => 'Bob Ray'] as $email => $name) {
            $this->db->prepare("INSERT INTO users (name, email, password_hash, is_active) VALUES (?, ?, '!', TRUE)")
                ->execute([$name, $email]);
        }
    }

    protected function tearDown(): void
    {
        $this->db->rollBack();
    }

    /**
     * Save a nickname for someone, as the profile form does
     */
    private function saveNickname(string $email, string $nickname): User
    {
        $user = User::findByEmail($email);
        $user->updateProfile(['name' => $user->name, 'nickname' => $nickname, 'email' => $email, 'password' => '']);

        return $user;
    }

    /**
     * Someone's nickname as saved
     */
    private function nicknameOf(string $email): ?string
    {
        return User::findByEmail($email)->nickname;
    }

    public function testSavesANickname()
    {
        $this->assertSame([], $this->saveNickname('ann@test.local', 'annie')->errors ?? []);
        $this->assertSame('annie', $this->nicknameOf('ann@test.local'));
    }

    public function testEmptyMeansNone()
    {
        $this->saveNickname('ann@test.local', 'annie');
        $this->saveNickname('ann@test.local', '  ');

        $this->assertNull($this->nicknameOf('ann@test.local'));
    }

    public function testMustBeOneShortWord()
    {
        foreach (['a', 'ann lee', 'way_too_long_for_a_nickname', 'ann!'] as $nickname) {
            $this->assertNotEmpty($this->saveNickname('ann@test.local', $nickname)->errors ?? [], $nickname);
        }
        $this->assertNull($this->nicknameOf('ann@test.local'));
    }

    public function testCantTakeSomeoneElsesOrMapChats()
    {
        $this->saveNickname('bob@test.local', 'Rocket');

        $this->assertSame(['nickname' => ['That nickname is taken.']], $this->saveNickname('ann@test.local', 'rocket')->errors);
        $this->assertSame(['nickname' => ['That nickname is taken.']], $this->saveNickname('ann@test.local', 'mapchat')->errors);
        $this->assertSame([], $this->saveNickname('bob@test.local', 'Rocket')->errors ?? [], 'keeping your own');
    }
}

<?php

namespace Tests\SignIn;

use App\ChatSocket\Sessions;
use App\Models\User;
use App\SignIn\Auth;
use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\TestDb;

/**
 * Guest accounts last GUEST_LIFETIME_HOURS: the countdown, logging out
 * when they're up, and deleting them. Each test runs in a transaction that
 * is rolled back
 */
class GuestTest extends TestCase
{
    private PDO $db;
    private string $timezoneBefore;

    protected function setUp(): void
    {
        $this->db = TestDb::get();
        $this->db->beginTransaction();
        $this->timezoneBefore = date_default_timezone_get();
    }

    protected function tearDown(): void
    {
        date_default_timezone_set($this->timezoneBefore);
        unset($_SESSION['user_id']);
        $this->db->rollBack();
    }

    /**
     * A guest account made this many hours ago
     */
    private function guestMadeHoursAgo(float $hours): User
    {
        $email = 'guest-' . bin2hex(random_bytes(4)) . '@guest.mapchat.local';
        $this->db->prepare("INSERT INTO users (name, email, password_hash, is_active, is_guest, created_at)
                            VALUES ('Guest 1234', ?, '!', 1, 1, NOW() - INTERVAL ? SECOND)")
            ->execute([$email, (int) ($hours * 3600)]);

        return User::findByEmail($email);
    }

    public function testHoursLeftCountFromTheSameTimeAsTheDeletion(): void
    {
        // The database's times are UTC; PHP's own time zone mustn't move them
        date_default_timezone_set('America/Toronto');
        $guest = $this->guestMadeHoursAgo(User::GUEST_LIFETIME_HOURS - 1.5);

        $this->assertSame(2, $guest->guestHoursLeft());
        $this->assertEqualsWithDelta(time() + 1.5 * 3600, $guest->guestExpiresAt(), 5);
    }

    public function testAGuestWhoseDayIsUpIsLoggedOut(): void
    {
        $expired = $this->guestMadeHoursAgo(User::GUEST_LIFETIME_HOURS + 0.1);
        $fresh = $this->guestMadeHoursAgo(1);

        $this->assertTrue($expired->isExpiredGuest());
        $this->assertFalse($fresh->isExpiredGuest());

        $_SESSION['user_id'] = $expired->id;
        $this->assertNull(Auth::getUser());

        $_SESSION['user_id'] = $fresh->id;
        $this->assertSame($fresh->id, Auth::getUser()?->id);
    }

    public function testTheChatSocketLetsAnExpiredGuestsSessionGo(): void
    {
        $expired = $this->guestMadeHoursAgo(User::GUEST_LIFETIME_HOURS + 0.1);
        $fresh = $this->guestMadeHoursAgo(1);
        foreach (['expired-session-1234567890' => $expired, 'fresh-session-12345678901' => $fresh] as $sessionId => $guest) {
            $this->db->prepare('INSERT INTO sessions (id, data, updated_at) VALUES (?, ?, ?)')
                ->execute([$sessionId, 'user_id|i:' . $guest->id . ';', time()]);
        }

        $stillLoggedIn = Sessions::getStillLoggedIn([
            'expired-session-1234567890' => $expired->id,
            'fresh-session-12345678901' => $fresh->id,
        ]);

        $this->assertSame(['fresh-session-12345678901'], $stillLoggedIn);
    }

    public function testExpiredGuestsAreDeletedAndOthersKept(): void
    {
        $expired = $this->guestMadeHoursAgo(User::GUEST_LIFETIME_HOURS + 0.1);
        $fresh = $this->guestMadeHoursAgo(1);

        User::deleteExpiredGuests();

        $this->assertNull(User::findByID($expired->id));
        $this->assertNotNull(User::findByID($fresh->id));
    }

    public function testAReturningGuestGetsTheirAccountOnlyWithinTheirDay(): void
    {
        $guest = User::createGuest();

        $this->assertSame($guest->id, User::findGuestByToken($guest->guestToken)?->id);

        $this->db->prepare('UPDATE users SET created_at = NOW() - INTERVAL ? HOUR WHERE id = ?')
            ->execute([User::GUEST_LIFETIME_HOURS + 1, $guest->id]);
        $this->assertNull(User::findGuestByToken($guest->guestToken));
    }
}

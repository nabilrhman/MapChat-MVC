<?php

namespace App\ChatSocket;

use App\Models\User;
use Core\DatabaseSessionHandler;
use PDO;

/**
 * The site's login sessions (Core\DatabaseSessionHandler), as the chat socket
 * sees them: a page's socket is let in by its session cookie, and cut when
 * that session ends
 */
class Sessions extends \Core\Model
{
    /** What PHP's session ids look like */
    const ID_PATTERN = '/^[A-Za-z0-9,-]{22,256}$/';

    /**
     * The user logged in on a session, or null. As Auth::getUser, a session
     * from before the password last changed counts as logged out
     */
    public static function getUser(string $sessionId): ?User
    {
        [$userId, $signInStamp] = static::getSignIn($sessionId);
        $user = $userId === null ? null : User::findByID($userId);
        if (!$user || !hash_equals($user->signInStamp(), $signInStamp)) {
            return null;
        }

        return $user;
    }

    /**
     * Who is logged in on a session and their sign-in stamp, as it was
     * stored. Read with PHP's own session code and the site's handler, so it
     * reads exactly as a page does
     *
     * @return array{0: ?int, 1: string} [user id or null, stamp or '']
     */
    protected static function getSignIn(string $sessionId): array
    {
        if (!preg_match(static::ID_PATTERN, $sessionId)) {
            return [null, ''];
        }

        static $hasHandler = false;
        if (!$hasHandler) {
            // Without the lock: one page's slow request mustn't hold up the whole socket
            session_set_save_handler(new DatabaseSessionHandler(isLocking: false), false);
            $hasHandler = true;
        }

        session_id($sessionId);
        // A session id the site didn't make gets a new, empty session; nothing is written
        session_start([
            'read_and_close' => true,
            'use_strict_mode' => true,
            'use_cookies' => false,
            'cache_limiter' => '',
            'gc_probability' => 0,
        ]);
        $isOurSession = session_id() === $sessionId;
        $userId = $isOurSession ? ($_SESSION['user_id'] ?? null) : null;
        $signInStamp = $isOurSession ? (string) ($_SESSION['sign_in_stamp'] ?? '') : '';
        $_SESSION = [];

        return [$userId === null ? null : (int) $userId, $signInStamp];
    }

    /**
     * Which of these sessions still exist, for users who still exist and
     * whose guest day isn't up. Logging out deletes the session, and a
     * guest's account stops working after a day. With stamps, a session from
     * before the user's password changed is over too
     *
     * @param array<string, int> $userIdsBySession Session id => user id
     * @param array<string, string> $stampsBySession Session id => the user's sign-in stamp when it connected
     *
     * @return string[] The session ids still good
     */
    public static function getStillLoggedIn(array $userIdsBySession, array $stampsBySession = []): array
    {
        if (!$userIdsBySession) {
            return [];
        }

        $db = static::getDB();

        [$sessionList, $sessionParams] = static::inList('session', array_keys($userIdsBySession));
        $stmt = $db->prepare("SELECT id FROM sessions WHERE id IN $sessionList");
        $stmt->execute($sessionParams);
        $sessions = $stmt->fetchAll(PDO::FETCH_COLUMN);

        [$userList, $userParams] = static::inList('user', array_unique($userIdsBySession));
        $stmt = $db->prepare("SELECT * FROM users WHERE id IN $userList AND NOT (" . User::expiredGuestSql() . ')');
        $stmt->execute($userParams);
        $usersById = [];
        foreach ($stmt->fetchAll(PDO::FETCH_CLASS, User::class) as $user) {
            $usersById[$user->id] = $user;
        }

        return array_values(array_filter($sessions, function (string $sessionId) use ($usersById, $userIdsBySession, $stampsBySession): bool {
            $user = $usersById[$userIdsBySession[$sessionId]] ?? null;
            if ($user === null) {
                return false;
            }
            $stamp = $stampsBySession[$sessionId] ?? null;

            return $stamp === null || hash_equals($user->signInStamp(), $stamp);
        }));
    }
}

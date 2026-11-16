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
     * The id of the user logged in on a session, or null. Read with PHP's own
     * session code and the site's handler, so it reads exactly as a page does
     */
    public static function getUserId(string $sessionId): ?int
    {
        if (!preg_match(static::ID_PATTERN, $sessionId)) {
            return null;
        }

        static $hasHandler = false;
        if (!$hasHandler) {
            session_set_save_handler(new DatabaseSessionHandler(), false);
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
        $userId = session_id() === $sessionId ? ($_SESSION['user_id'] ?? null) : null;
        $_SESSION = [];

        return $userId === null ? null : (int) $userId;
    }

    /**
     * Which of these sessions still exist, for users who still exist and
     * whose guest day isn't up. Logging out deletes the session, and a
     * guest's account stops working after a day
     *
     * @param array<string, int> $userIdsBySession Session id => user id
     *
     * @return string[] The session ids still good
     */
    public static function getStillLoggedIn(array $userIdsBySession): array
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
        $stmt = $db->prepare("SELECT id FROM users WHERE id IN $userList AND NOT (" . User::expiredGuestSql() . ')');
        $stmt->execute($userParams);
        $users = array_flip(array_map(intval(...), $stmt->fetchAll(PDO::FETCH_COLUMN)));

        return array_values(array_filter($sessions, fn ($id) => isset($users[$userIdsBySession[$id]])));
    }
}

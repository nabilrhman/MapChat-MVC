<?php

namespace App\ChatSocket;

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
     *
     * @param string $sessionId
     *
     * @return int|null
     */
    public static function getUserId($sessionId)
    {
        if (!preg_match(static::ID_PATTERN, $sessionId)) {
            return null;
        }

        static $hasHandler = false;
        if (!$hasHandler) {
            session_set_save_handler(new \Core\DatabaseSessionHandler(), false);
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
     * Which of these sessions still exist, for users who still exist. Logging
     * out deletes the session, and a guest's account is deleted after a day
     *
     * @param array $userIdsBySession Session id => user id
     *
     * @return array The session ids still good
     */
    public static function getStillLoggedIn($userIdsBySession)
    {
        if (!$userIdsBySession) {
            return [];
        }

        $db = static::getDB();
        $sessionList = implode(', ', array_map([$db, 'quote'], array_keys($userIdsBySession)));
        $userList = implode(', ', array_map('intval', array_unique($userIdsBySession)));

        $sessions = $db->query("SELECT id FROM sessions WHERE id IN ($sessionList)")->fetchAll(PDO::FETCH_COLUMN);
        $users = array_flip($db->query("SELECT id FROM users WHERE id IN ($userList)")->fetchAll(PDO::FETCH_COLUMN));

        return array_values(array_filter($sessions, fn ($id) => isset($users[$userIdsBySession[$id]])));
    }

    /**
     * Mark sessions with an open socket as used, as each poll did, so they
     * don't expire while the page stays open. At most once an hour
     *
     * @param array $sessionIds
     *
     * @return void
     */
    public static function touch($sessionIds)
    {
        if (!$sessionIds) {
            return;
        }

        $db = static::getDB();
        $list = implode(', ', array_map([$db, 'quote'], $sessionIds));
        $stmt = $db->prepare("UPDATE sessions SET updated_at = :now WHERE id IN ($list) AND updated_at < :now2 - 3600");
        $stmt->bindValue(':now', time(), PDO::PARAM_INT);
        $stmt->bindValue(':now2', time(), PDO::PARAM_INT);
        $stmt->execute();
    }
}

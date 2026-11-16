<?php

namespace App\Models;

use PDO;

/**
 * Chat messages: the open room and private messages
 */
class Chat extends \Core\Model
{
    /** Longest message allowed, in characters */
    const MAX_LENGTH = 500;

    /** Messages a signed-up user can send per minute */
    const USER_PER_MINUTE = 30;

    /** Messages a guest can send per minute */
    const GUEST_PER_MINUTE = 10;

    /** Most messages sent to the page at once: when it opens, checks for new ones or asks for older ones */
    const HISTORY = 100;

    // Private chats the page opens a tab for, however busy the room has been
    const CONVERSATIONS = 20;

    /** The MapChat account: the welcome messages, and it chats back (Bot) */
    const BOT_EMAIL = 'bot@mapchat.local';

    /**
     * How many messages this person sent in the last few seconds
     *
     * @param string $email
     * @param int $seconds
     *
     * @return int
     */
    public static function countRecent($email, $seconds)
    {
        $sql = 'SELECT COUNT(*) FROM chats
                WHERE email = :email
                  AND timestamp > NOW() - INTERVAL :seconds SECOND';

        $stmt = static::getDB()->prepare($sql);
        $stmt->bindValue(':email', $email, PDO::PARAM_STR);
        $stmt->bindValue(':seconds', $seconds, PDO::PARAM_INT);
        $stmt->execute();

        return (int) $stmt->fetchColumn();
    }

    /**
     * Save the message
     *
     * @return boolean  True if the message was saved
     */
    public function send()
    {
        // Stored as typed; Twig escapes it when the chat is shown
        $sql = 'INSERT INTO chats (email, to_email, body)
                    VALUES (:email, :to_email, :body)';

        $stmt = static::getDB()->prepare($sql);
        $stmt->bindValue(':body', $this->body, PDO::PARAM_STR);
        $stmt->bindValue(':email', $this->email, PDO::PARAM_STR);
        $stmt->bindValue(':to_email', $this->to_email ?? null, PDO::PARAM_STR);

        return $stmt->execute();
    }

    /**
     * The id of the message this request saved last
     *
     * @return string
     */
    public static function lastId()
    {
        return static::getDB()->lastInsertId();
    }

    /**
     * The newest messages the viewer may see, oldest first
     *
     * @param string $viewer The viewer's email
     *
     * @return array
     */
    public static function getRecent($viewer)
    {
        // Each kind of message is read from its own index, so other people's
        // private messages are never scanned past
        $limit = ' ORDER BY id DESC LIMIT ' . static::HISTORY;
        $sql = static::selectSql() . '
                JOIN (
                    (SELECT id FROM chats WHERE to_email IS NULL' . $limit . ')
                    UNION ALL
                    (SELECT id FROM chats WHERE to_email = :viewer' . $limit . ')
                    UNION ALL
                    (SELECT id FROM chats WHERE email = :viewer AND to_email IS NOT NULL' . $limit . ')
                   ' . $limit . '
                ) AS recent
                ON recent.id = chats.id
                ORDER BY chats.id';

        $stmt = static::getDB()->prepare($sql);
        $stmt->bindValue(':viewer', $viewer, PDO::PARAM_STR);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * The viewer's newest private chats, each with the other person, the
     * newest message's id and how many the viewer hasn't seen. The page may
     * not hold those messages: a busy room pushes them out of the newest page
     *
     * @param string $viewer The viewer's email
     * @param int $upToId The page's newest message; the chat's polling brings later ones
     *
     * @return array
     */
    public static function getConversations($viewer, $upToId)
    {
        $sql = 'SELECT users.id AS user_id, users.name, users.nickname, users.is_guest,
                       MAX(private.id) AS newest_id, SUM(private.is_unread) AS unread
                FROM (
                    (SELECT email AS partner, id, read_at IS NULL AS is_unread FROM chats
                     WHERE to_email = :viewer AND id <= :up_to_id)
                    UNION ALL
                    (SELECT to_email, id, FALSE FROM chats
                     WHERE email = :viewer AND to_email IS NOT NULL AND id <= :up_to_id)
                ) AS private
                JOIN users
                ON private.partner = users.email
                GROUP BY users.id, users.name, users.nickname, users.is_guest
                ORDER BY newest_id DESC
                LIMIT ' . static::CONVERSATIONS;

        $stmt = static::getDB()->prepare($sql);
        $stmt->bindValue(':viewer', $viewer, PDO::PARAM_STR);
        $stmt->bindValue(':up_to_id', $upToId, PDO::PARAM_INT);
        $stmt->execute();

        return \App\Mention::withShownNames($stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * Mark the private messages a person sent the viewer as seen, up to the
     * given id. Ones that came later stay unread
     *
     * @param string $viewer The viewer's email
     * @param int $senderId
     * @param int $upToId
     *
     * @return void
     */
    public static function markRead($viewer, $senderId, $upToId)
    {
        $sql = 'UPDATE chats
                JOIN users
                ON chats.email = users.email
                SET chats.read_at = CURRENT_TIMESTAMP
                WHERE chats.to_email = :viewer AND users.id = :sender_id
                      AND chats.id <= :up_to_id AND chats.read_at IS NULL';

        $stmt = static::getDB()->prepare($sql);
        $stmt->bindValue(':viewer', $viewer, PDO::PARAM_STR);
        $stmt->bindValue(':sender_id', $senderId, PDO::PARAM_INT);
        $stmt->bindValue(':up_to_id', $upToId, PDO::PARAM_INT);
        $stmt->execute();
    }

    /**
     * Messages the viewer may see that came after the given id
     *
     * @param string $viewer The viewer's email
     * @param int $afterId
     *
     * @return array
     */
    public static function getAfter($viewer, $afterId)
    {
        $sql = static::visibleSql() . ' AND chats.id > :after_id ORDER BY chats.id LIMIT ' . static::HISTORY;

        $stmt = static::getDB()->prepare($sql);
        $stmt->bindValue(':viewer', $viewer, PDO::PARAM_STR);
        $stmt->bindValue(':after_id', $afterId, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * The page of messages the viewer may see just before the given id,
     * oldest first
     *
     * @param string $viewer The viewer's email
     * @param int $beforeId
     *
     * @return array
     */
    public static function getBefore($viewer, $beforeId)
    {
        $sql = static::visibleSql() . ' AND chats.id < :before_id ORDER BY chats.id DESC LIMIT ' . static::HISTORY;

        $stmt = static::getDB()->prepare($sql);
        $stmt->bindValue(':viewer', $viewer, PDO::PARAM_STR);
        $stmt->bindValue(':before_id', $beforeId, PDO::PARAM_INT);
        $stmt->execute();

        return array_reverse($stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * The query for messages the viewer may see: the open room and their own
     * private ones
     *
     * @return string
     */
    protected static function visibleSql()
    {
        return static::selectSql() . '
                WHERE (chats.to_email IS NULL OR chats.to_email = :viewer OR chats.email = :viewer)';
    }

    /**
     * The query for each message with its sender and recipient, without a filter
     *
     * @return string
     */
    protected static function selectSql()
    {
        return 'SELECT chats.id, chats.body, chats.timestamp, users.id AS user_id, users.name, users.email, users.avatar,
                       users.email LIKE \'%@demo.mapchat.local\' AS is_demo, users.nickname, users.is_guest,
                       IF(users.share_location, userinfo.city, NULL) AS city,
                       IF(users.share_location, userinfo.country, NULL) AS country,
                       IF(users.share_location, userinfo.lng, NULL) AS lng,
                       IF(users.share_location, userinfo.timezone, NULL) AS timezone,
                       recipients.id AS to_user_id, recipients.name AS to_name,
                       recipients.nickname AS to_nickname, recipients.is_guest AS to_is_guest
                FROM chats
                LEFT JOIN users
                ON chats.email = users.email
                LEFT JOIN users AS recipients
                ON chats.to_email = recipients.email
                LEFT JOIN userinfo
                ON users.email = userinfo.email';
    }
}

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

    /** Most messages sent to the page at once, when it opens or checks for new ones */
    const HISTORY = 100;

    /** The welcome account; nobody can message it privately */
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
     * The newest messages the viewer may see, oldest first
     *
     * @param string $viewer The viewer's email
     *
     * @return array
     */
    public static function getRecent($viewer)
    {
        $sql = 'SELECT * FROM (' . static::visibleSql() . ' ORDER BY chats.id DESC LIMIT ' . static::HISTORY . ') AS recent
                ORDER BY id';

        $stmt = static::getDB()->prepare($sql);
        $stmt->bindValue(':viewer', $viewer, PDO::PARAM_STR);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
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
     * The query for messages the viewer may see: the open room and their own
     * private ones
     *
     * @return string
     */
    protected static function visibleSql()
    {
        return 'SELECT chats.id, chats.body, chats.timestamp, users.id AS user_id, users.name, users.email, users.avatar, users.is_guest,
                       IF(users.share_location, userinfo.city, NULL) AS city,
                       IF(users.share_location, userinfo.country, NULL) AS country,
                       IF(users.share_location, userinfo.lng, NULL) AS lng,
                       IF(users.share_location, userinfo.timezone, NULL) AS timezone,
                       recipients.id AS to_user_id, recipients.name AS to_name
                FROM chats
                LEFT JOIN users
                ON chats.email = users.email
                LEFT JOIN users AS recipients
                ON chats.to_email = recipients.email
                LEFT JOIN userinfo
                ON users.email = userinfo.email
                WHERE (chats.to_email IS NULL OR chats.to_email = :viewer OR chats.email = :viewer)';
    }
}

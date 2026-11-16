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

    /** What a page's id for a message it sends looks like, e.g. a UUID */
    const CLIENT_ID_PATTERN = '/^[A-Za-z0-9-]{8,36}$/';

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
     * Send a message from a person, with the room's rules: a length limit,
     * a rate limit, and private messages only to someone who can get them.
     * Sending with the same client id again gives back the message saved the
     * first time, so a page that lost the reply can safely try again
     *
     * @param User $sender
     * @param string $body
     * @param int|null $toUserId The recipient's id, or null for the open room
     * @param string|null $clientId The sending page's id for this message
     *
     * @return array ['id' => the message's id], or ['status' => HTTP status, 'error' => shown to the sender]
     */
    public static function sendFrom($sender, $body, $toUserId = null, $clientId = null)
    {
        if ($clientId !== null && !preg_match(static::CLIENT_ID_PATTERN, $clientId)) {
            return ['status' => 400, 'error' => 'Your message could not be sent.'];
        }

        // Before the limits: the first try may be what used them up
        $savedId = $clientId === null ? null : static::findSent($sender->email, $clientId);
        if ($savedId !== null) {
            return ['id' => $savedId];
        }

        $body = trim($body);
        if ($body === '') {
            return ['status' => 400, 'error' => 'Type a message first.'];
        }

        if (mb_strlen($body) > static::MAX_LENGTH) {
            return ['status' => 400, 'error' => 'Messages can be up to ' . static::MAX_LENGTH . ' characters.'];
        }

        // Keep one person from flooding the room; guests get a lower limit
        $limit = $sender->is_guest ? static::GUEST_PER_MINUTE : static::USER_PER_MINUTE;
        if (static::countRecent($sender->email, 60) >= $limit) {
            return ['status' => 429, 'error' => 'You are sending messages too fast. Wait a moment and try again.'];
        }

        // A private message goes to one other person, never to yourself, and
        // to MapChat only while it can write back
        $toEmail = null;
        if ($toUserId !== null) {
            $recipient = User::findByID($toUserId);

            if (!$recipient || $recipient->id == $sender->id || ($recipient->email === static::BOT_EMAIL && !Bot::canReply())) {
                return ['status' => 400, 'error' => 'You can\'t message this person.'];
            }

            $toEmail = $recipient->email;
        }

        $chat = new static(['email' => $sender->email, 'to_email' => $toEmail, 'body' => $body, 'client_id' => $clientId]);
        try {
            $chat->send();
        } catch (\PDOException $e) {
            // The same message sent twice at once, from two tries: the other one saved it
            $savedId = $clientId === null ? null : static::findSent($sender->email, $clientId);
            if ($savedId !== null) {
                return ['id' => $savedId];
            }
            throw $e;
        }

        $id = (int) static::lastId();
        Bot::heard($sender->email, $id, $toEmail, $body);

        return ['id' => $id];
    }

    /**
     * The message a person already sent with this client id, if any
     *
     * @param string $email
     * @param string $clientId
     *
     * @return int|null Its id
     */
    protected static function findSent($email, $clientId)
    {
        $stmt = static::getDB()->prepare('SELECT id FROM chats WHERE email = :email AND client_id = :client_id');
        $stmt->bindValue(':email', $email, PDO::PARAM_STR);
        $stmt->bindValue(':client_id', $clientId, PDO::PARAM_STR);
        $stmt->execute();

        $id = $stmt->fetchColumn();

        return $id === false ? null : (int) $id;
    }

    /**
     * Save the message
     *
     * @return boolean  True if the message was saved
     */
    public function send()
    {
        // Only named when there is one, so a page sending before the
        // column is added (database/migrate.php) still works
        $hasClientId = isset($this->client_id);

        // Stored as typed; Twig escapes it when the chat is shown
        $sql = 'INSERT INTO chats (email, to_email, body' . ($hasClientId ? ', client_id' : '') . ')
                    VALUES (:email, :to_email, :body' . ($hasClientId ? ', :client_id' : '') . ')';

        $stmt = static::getDB()->prepare($sql);
        $stmt->bindValue(':body', $this->body, PDO::PARAM_STR);
        $stmt->bindValue(':email', $this->email, PDO::PARAM_STR);
        $stmt->bindValue(':to_email', $this->to_email ?? null, PDO::PARAM_STR);
        if ($hasClientId) {
            $stmt->bindValue(':client_id', $this->client_id, PDO::PARAM_STR);
        }

        $isSaved = $stmt->execute();
        if ($isSaved) {
            \App\ChatSocket\Signal::newMessages();
        }

        return $isSaved;
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
     * Messages the viewer may see that came after the given id, up to a page
     *
     * @param string $viewer The viewer's email
     * @param int $afterId
     * @param int $upToId The newest id to include; the chat socket stops where its own reading has got to
     *
     * @return array
     */
    public static function getAfter($viewer, $afterId, $upToId = PHP_INT_MAX)
    {
        // Read from each kind's own index, as in getRecent. Filtering every
        // newer message instead made an idle viewer's poll reread all of
        // other people's private messages each second
        $after = ' AND id > :after_id AND id <= :up_to_id ORDER BY id LIMIT ' . static::HISTORY;
        $sql = static::selectSql() . '
                JOIN (
                    (SELECT id FROM chats WHERE to_email IS NULL' . $after . ')
                    UNION ALL
                    (SELECT id FROM chats WHERE to_email = :viewer' . $after . ')
                    UNION ALL
                    (SELECT id FROM chats WHERE email = :viewer AND to_email IS NOT NULL' . $after . ')
                    ORDER BY id LIMIT ' . static::HISTORY . '
                ) AS newer
                ON newer.id = chats.id
                ORDER BY chats.id';

        $stmt = static::getDB()->prepare($sql);
        $stmt->bindValue(':viewer', $viewer, PDO::PARAM_STR);
        $stmt->bindValue(':after_id', $afterId, PDO::PARAM_INT);
        $stmt->bindValue(':up_to_id', $upToId, PDO::PARAM_INT);
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
     * The page of messages before an id: one tab's, or (for a page from
     * before tabs loaded their own) all the viewer may see
     *
     * @param string $viewer The viewer's email
     * @param string|null $conversation "everyone", the other person's id, or null
     * @param int $beforeId
     *
     * @return array
     */
    public static function getOlder($viewer, $conversation, $beforeId)
    {
        if ($conversation === 'everyone') {
            return static::getRoomBefore($beforeId);
        }
        if ($conversation !== null && ctype_digit((string) $conversation)) {
            return static::getPrivateBefore($viewer, (int) $conversation, $beforeId);
        }

        return static::getBefore($viewer, $beforeId);
    }

    /**
     * The page of open-room messages just before the given id, oldest first
     *
     * @param int $beforeId
     *
     * @return array
     */
    public static function getRoomBefore($beforeId)
    {
        $sql = static::selectSql() . '
                JOIN (
                    SELECT id FROM chats WHERE to_email IS NULL AND id < :before_id ORDER BY id DESC LIMIT ' . static::HISTORY . '
                ) AS page
                ON page.id = chats.id
                ORDER BY chats.id';

        $stmt = static::getDB()->prepare($sql);
        $stmt->bindValue(':before_id', $beforeId, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * The page of the viewer's private chat with one person just before the
     * given id, oldest first. Read straight from that chat's index, so a busy
     * room in between costs nothing
     *
     * @param string $viewer The viewer's email
     * @param int $partnerId The other person's id
     * @param int $beforeId
     *
     * @return array
     */
    public static function getPrivateBefore($viewer, $partnerId, $beforeId)
    {
        $partner = User::findByID($partnerId);
        if (!$partner) {
            return [];
        }

        $before = ' AND id < :before_id ORDER BY id DESC LIMIT ' . static::HISTORY;
        $sql = static::selectSql() . '
                JOIN (
                    (SELECT id FROM chats WHERE to_email = :viewer AND email = :partner' . $before . ')
                    UNION ALL
                    (SELECT id FROM chats WHERE to_email = :partner AND email = :viewer' . $before . ')
                    ORDER BY id DESC LIMIT ' . static::HISTORY . '
                ) AS page
                ON page.id = chats.id
                ORDER BY chats.id';

        $stmt = static::getDB()->prepare($sql);
        $stmt->bindValue(':viewer', $viewer, PDO::PARAM_STR);
        $stmt->bindValue(':partner', $partner->email, PDO::PARAM_STR);
        $stmt->bindValue(':before_id', $beforeId, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * The ids of the messages after the given one, whoever they're for. The
     * chat socket reads on from here and sends each to whoever may see it
     *
     * @param int $afterId
     * @param int $limit
     *
     * @return int[]
     */
    public static function getIdsAfter($afterId, $limit)
    {
        $stmt = static::getDB()->prepare('SELECT id FROM chats WHERE id > :after_id ORDER BY id LIMIT ' . (int) $limit);
        $stmt->bindValue(':after_id', $afterId, PDO::PARAM_INT);
        $stmt->execute();

        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /**
     * The newest message's id, or 0 in an empty room
     *
     * @return int
     */
    public static function getNewestId()
    {
        return (int) static::getDB()->query('SELECT COALESCE(MAX(id), 0) FROM chats')->fetchColumn();
    }

    /**
     * These messages with their sender and recipient, oldest first
     *
     * @param int[] $ids
     *
     * @return array
     */
    public static function getByIds($ids)
    {
        if (!$ids) {
            return [];
        }

        $list = implode(', ', array_map('intval', $ids));
        $stmt = static::getDB()->query(static::selectSql() . " WHERE chats.id IN ($list) ORDER BY chats.id");

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
        return 'SELECT chats.id, chats.body, chats.timestamp, chats.to_email, users.id AS user_id, users.name, users.email, users.avatar,
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

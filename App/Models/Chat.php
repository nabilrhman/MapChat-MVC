<?php

namespace App\Models;

use PDO;
use App\ChatSocket\Signal;
use App\Mention;

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

    /** Private chats the page opens a tab for, however busy the room has been */
    const CONVERSATIONS = 20;

    /** The MapChat account: the welcome messages, and it chats back (Bot) */
    const BOT_EMAIL = 'bot@mapchat.local';

    /** What a page's id for a message it sends looks like, e.g. a UUID */
    const CLIENT_ID_PATTERN = '/^[A-Za-z0-9-]{8,36}$/';

    /**
     * The messages a viewer may see, each kind read from its own index, so
     * other people's private messages are never scanned past: the open
     * room, to them, and from them
     */
    const VISIBLE_TO_VIEWER = ['to_email IS NULL', 'to_email = :viewer', 'email = :viewer AND to_email IS NOT NULL'];

    /** One private chat's messages, either way */
    const BETWEEN_VIEWER_AND_PARTNER = ['to_email = :viewer AND email = :partner', 'to_email = :partner AND email = :viewer'];

    /**
     * How many messages this person sent in the last few seconds
     */
    public static function countRecent(string $email, int $seconds): int
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
     * @param int|null $toUserId The recipient's id, or null for the open room
     * @param string|null $clientId The sending page's id for this message
     *
     * @return array{id: int}|array{status: int, error: string} The message's id, or an HTTP status and what to show the sender
     */
    public static function sendFrom(User $sender, string $body, ?int $toUserId = null, ?string $clientId = null): array
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

            if (!$recipient || $recipient->id === $sender->id || ($recipient->email === static::BOT_EMAIL && !Bot::canReply())) {
                return ['status' => 400, 'error' => 'You can\'t message this person.'];
            }

            $toEmail = $recipient->email;
        }

        try {
            $id = static::save($sender->email, $toEmail, $body, $clientId);
        } catch (\PDOException $e) {
            // The same message sent twice at once, from two tries: the other one saved it
            $savedId = $clientId === null ? null : static::findSent($sender->email, $clientId);
            if ($savedId !== null) {
                return ['id' => $savedId];
            }
            throw $e;
        }

        Bot::onMessage($sender->email, $id, $toEmail, $body);

        return ['id' => $id];
    }

    /**
     * The message a person already sent with this client id, if any
     *
     * @return int|null Its id
     */
    protected static function findSent(string $email, string $clientId): ?int
    {
        $stmt = static::getDB()->prepare('SELECT id FROM chats WHERE email = :email AND client_id = :client_id');
        $stmt->bindValue(':email', $email, PDO::PARAM_STR);
        $stmt->bindValue(':client_id', $clientId, PDO::PARAM_STR);
        $stmt->execute();

        $id = $stmt->fetchColumn();

        return $id === false ? null : (int) $id;
    }

    /**
     * Save a message as it is, without the room's rules (sendFrom has
     * those), and tell the chat socket
     *
     * @param string|null $toEmail The recipient, or null for the open room
     * @param string|null $clientId The sending page's id for this message
     *
     * @return int The message's id
     */
    public static function save(string $email, ?string $toEmail, string $body, ?string $clientId = null): int
    {
        // Stored as typed; Twig escapes it when the chat is shown
        $db = static::getDB();
        $stmt = $db->prepare('INSERT INTO chats (email, to_email, body, client_id) VALUES (:email, :to_email, :body, :client_id)');
        $stmt->bindValue(':email', $email, PDO::PARAM_STR);
        $stmt->bindValue(':to_email', $toEmail, PDO::PARAM_STR);
        $stmt->bindValue(':body', $body, PDO::PARAM_STR);
        $stmt->bindValue(':client_id', $clientId, PDO::PARAM_STR);
        $stmt->execute();

        $id = (int) $db->lastInsertId();
        Signal::newMessages();

        return $id;
    }

    /**
     * The newest messages the viewer may see, oldest first
     *
     * @param string $viewer The viewer's email
     *
     * @return array[] Message rows, as selectSql gives them
     */
    public static function getRecent(string $viewer): array
    {
        $sql = static::pageSql(static::VISIBLE_TO_VIEWER, '', 'DESC');

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
     * @param int $upToId The page's newest message; the chat brings later ones
     *
     * @return array[] Each with user_id, name, nickname, is_guest, newest_id, unread and shown_name
     */
    public static function getConversations(string $viewer, int $upToId): array
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

        return Mention::withShownNames($stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * Mark the private messages a person sent the viewer as seen, up to the
     * given id. Ones that came later stay unread
     *
     * @param string $viewer The viewer's email
     */
    public static function markRead(string $viewer, int $senderId, int $upToId): void
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
     * @param int $upToId The newest id to include; the chat socket stops where its own reading has got to
     *
     * @return array[] Message rows, oldest first
     */
    public static function getAfter(string $viewer, int $afterId, int $upToId = PHP_INT_MAX): array
    {
        // Filtering every newer message instead made an idle viewer's poll
        // reread all of other people's private messages each second
        $sql = static::pageSql(static::VISIBLE_TO_VIEWER, ' AND id > :after_id AND id <= :up_to_id', 'ASC');

        $stmt = static::getDB()->prepare($sql);
        $stmt->bindValue(':viewer', $viewer, PDO::PARAM_STR);
        $stmt->bindValue(':after_id', $afterId, PDO::PARAM_INT);
        $stmt->bindValue(':up_to_id', $upToId, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * The page of messages the viewer may see just before the given id
     *
     * @param string $viewer The viewer's email
     *
     * @return array[] Message rows, oldest first
     */
    public static function getBefore(string $viewer, int $beforeId): array
    {
        $sql = static::pageSql(static::VISIBLE_TO_VIEWER, ' AND id < :before_id', 'DESC');

        $stmt = static::getDB()->prepare($sql);
        $stmt->bindValue(':viewer', $viewer, PDO::PARAM_STR);
        $stmt->bindValue(':before_id', $beforeId, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * The page of messages before an id: one tab's, or (for a page from
     * before tabs loaded their own) all the viewer may see
     *
     * @param string $viewer The viewer's email
     * @param string|null $conversation "everyone", the other person's id, or null
     *
     * @return array[] Message rows, oldest first
     */
    public static function getOlder(string $viewer, ?string $conversation, int $beforeId): array
    {
        if ($conversation === 'everyone') {
            return static::getRoomBefore($beforeId);
        }
        if ($conversation !== null && ctype_digit($conversation)) {
            return static::getPrivateBefore($viewer, (int) $conversation, $beforeId);
        }

        return static::getBefore($viewer, $beforeId);
    }

    /**
     * The page of open-room messages just before the given id
     *
     * @return array[] Message rows, oldest first
     */
    public static function getRoomBefore(int $beforeId): array
    {
        $stmt = static::getDB()->prepare(static::pageSql(['to_email IS NULL'], ' AND id < :before_id', 'DESC'));
        $stmt->bindValue(':before_id', $beforeId, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * The page of the viewer's private chat with one person just before the
     * given id. Read straight from that chat's index, so a busy room in
     * between costs nothing
     *
     * @param string $viewer The viewer's email
     * @param int $partnerId The other person's id
     *
     * @return array[] Message rows, oldest first
     */
    public static function getPrivateBefore(string $viewer, int $partnerId, int $beforeId): array
    {
        $partner = User::findByID($partnerId);
        if (!$partner) {
            return [];
        }

        $stmt = static::getDB()->prepare(static::pageSql(static::BETWEEN_VIEWER_AND_PARTNER, ' AND id < :before_id', 'DESC'));
        $stmt->bindValue(':viewer', $viewer, PDO::PARAM_STR);
        $stmt->bindValue(':partner', $partner->email, PDO::PARAM_STR);
        $stmt->bindValue(':before_id', $beforeId, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * The ids of the messages after the given one, whoever they're for. The
     * chat socket and worker read on from here (NewMessageReader)
     *
     * @return int[]
     */
    public static function getIdsAfter(int $afterId, int $limit): array
    {
        $stmt = static::getDB()->prepare('SELECT id FROM chats WHERE id > :after_id ORDER BY id LIMIT ' . $limit);
        $stmt->bindValue(':after_id', $afterId, PDO::PARAM_INT);
        $stmt->execute();

        return array_map(intval(...), $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /**
     * The newest message's id, or 0 in an empty room
     */
    public static function getNewestId(): int
    {
        return (int) static::getDB()->query('SELECT COALESCE(MAX(id), 0) FROM chats')->fetchColumn();
    }

    /**
     * These messages with their sender and recipient
     *
     * @return array[] Message rows, oldest first
     * @param int[] $ids
     */
    public static function getByIds(array $ids): array
    {
        if (!$ids) {
            return [];
        }

        [$idList, $idParams] = static::inList('id', $ids);
        $stmt = static::getDB()->prepare(static::selectSql() . " WHERE chats.id IN $idList ORDER BY chats.id");
        $stmt->execute($idParams);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * The query for one page of messages, oldest first: up to HISTORY ids of
     * each kind, read from that kind's own index, then the HISTORY nearest
     * of all of them
     *
     * @param string[] $kinds SQL for each kind of message, e.g. VISIBLE_TO_VIEWER
     * @param string $condition SQL added to each kind's, e.g. ' AND id < :before_id'
     * @param string $direction ASC for the page just after a point, DESC for the newest or just before
     */
    protected static function pageSql(array $kinds, string $condition, string $direction): string
    {
        $order = " ORDER BY id $direction LIMIT " . static::HISTORY;
        $selects = array_map(fn ($kind) => "(SELECT id FROM chats WHERE $kind$condition$order)", $kinds);

        return static::selectSql() . '
                JOIN (' . implode(' UNION ALL ', $selects) . $order . ') AS page
                ON page.id = chats.id
                ORDER BY chats.id';
    }

    /**
     * The query for each message with its sender and recipient, without a filter
     */
    protected static function selectSql(): string
    {
        return 'SELECT chats.id, chats.body, chats.timestamp, chats.to_email, users.id AS user_id, users.name, users.email, users.avatar,
                       ' . DemoChat::isDemoSql() . ' AS is_demo, users.nickname, users.is_guest,
                       ' . UserInfo::sharedPlaceSql() . ',
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

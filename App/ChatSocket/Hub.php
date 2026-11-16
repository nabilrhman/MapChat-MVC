<?php

namespace App\ChatSocket;

use Amp\Http\Server\Request;
use Amp\Http\Server\Response;
use Amp\Websocket\Server\WebsocketClientHandler;
use Amp\Websocket\WebsocketClient;
use App\Config;
use App\Mention;
use App\Models\Chat;
use App\Models\UserInfo;
use Core\View;
use Revolt\EventLoop;
use function Amp\async;
use function Amp\delay;

/**
 * The chat over WebSockets, one per open chat page. Messages are read from
 * the database in id order, by one reader for everyone, and each goes to
 * the pages allowed to see it. A page that (re)connects says which message
 * it has last; it's caught up from the database from there, then joins the
 * live stream, so it gets every message once and in order.
 *
 * Messages a page sends are saved with the same rules as the HTTP chat
 * (Chat::sendFrom), and come back to it through the stream like everyone
 * else's. Presence (who's online) and typing go out as they change.
 *
 * Everything here runs in one process and one event loop. Database calls
 * block it for their few milliseconds; nothing else waits between reading
 * the state and changing it, so no two handlers interleave.
 */
class Hub implements WebsocketClientHandler
{
    /** The longest a new message waits to be read when no Signal arrives */
    const TAIL_SECONDS = 0.5;

    /** Most messages read in one go */
    const TAIL_BATCH = 500;

    /**
     * A missing id (a send that took it hasn't committed yet, or a failed or
     * rolled-back insert that never will) is waited for this long before
     * reading on without it. A send commits in milliseconds
     */
    const GAP_SECONDS = 2;

    /** A catching-up page waits while more than this is queued for it */
    const CATCH_UP_QUEUE_BYTES = 262144;

    /** How often to look for presence changes not seen as they happen (demo people, polling tabs) */
    const PRESENCE_SECONDS = 5;

    /** How often to mark everyone with a socket open as online in the database (UserInfo::ONLINE_SECONDS is 60) */
    const MARK_ONLINE_SECONDS = 30;

    /** How often to check that each page's session is still logged in */
    const SESSION_CHECK_SECONDS = 15;

    /** How often to keep open pages' sessions from expiring (Sessions::touch) */
    const SESSION_TOUCH_SECONDS = 600;

    /** How long "typing" holds without a fresh word from the page */
    const TYPING_SECONDS = 6;

    /** "Typing" is passed on at most this often per page */
    const TYPING_FORWARD_SECONDS = 1;

    /** @var Tab[] By connection id */
    private array $tabs = [];

    /** @var Tab[][] By email, then connection id */
    private array $tabsByEmail = [];

    /** The newest id read and sent on; every live page has everything up to here */
    private int $tailId = 0;

    /** When the reader first stopped at a missing id, or null */
    private ?float $gapSince = null;

    private bool $isTailQueued = false;

    /** Who's announced as online, as people rows by email */
    private array $announced = [];

    /** When each person whose last page closed stops counting as online, by email */
    private array $graceUntil = [];

    private bool $isPresenceQueued = false;

    /** How long a closed last page keeps its person online, for a reload or a network blip */
    private float $graceSeconds;

    private float $sessionCheckSeconds;

    public function __construct()
    {
        $this->graceSeconds = (float) Config::get('CHAT_PRESENCE_GRACE_SECONDS', 10);
        $this->sessionCheckSeconds = (float) Config::get('CHAT_SESSION_CHECK_SECONDS', static::SESSION_CHECK_SECONDS);
    }

    /**
     * Start reading messages and keeping presence. The reader starts at the
     * newest message: pages that connect say where they are themselves
     *
     * @return void
     */
    public function start()
    {
        $this->tailId = Chat::getNewestId();
        foreach (UserInfo::getPeople(UserInfo::getOnlineEmails()) as $person) {
            $this->announced[$person['email']] = $person;
        }

        EventLoop::repeat(static::TAIL_SECONDS, fn () => $this->safely($this->readNewMessages(...)));
        EventLoop::repeat(1, fn () => $this->safely($this->expireTyping(...)));
        EventLoop::repeat(static::PRESENCE_SECONDS, fn () => $this->safely($this->updatePresence(...)));
        EventLoop::repeat(static::MARK_ONLINE_SECONDS, fn () => $this->safely(fn () => UserInfo::markOnlineNow(array_keys($this->tabsByEmail))));
        EventLoop::repeat($this->sessionCheckSeconds, fn () => $this->safely($this->closeLoggedOutTabs(...)));
        EventLoop::repeat(static::SESSION_TOUCH_SECONDS, fn () => $this->safely(fn () => Sessions::touch($this->getSessionIds())));
    }

    /**
     * Close every page with "restarting" (1012); they reconnect to the next process
     *
     * @return void
     */
    public function closeAll()
    {
        foreach ($this->tabs as $tab) {
            $tab->close(1012, 'Restarting');
        }
    }

    public function countTabs(): int
    {
        return count($this->tabs);
    }

    public function countTabsOf(string $email): int
    {
        return count($this->tabsByEmail[$email] ?? []);
    }

    /**
     * A message was saved (Signal): read it now rather than on the next round
     *
     * @return void
     */
    public function hearNewMessages()
    {
        if ($this->isTailQueued) {
            return;
        }
        $this->isTailQueued = true;
        EventLoop::defer(function () {
            $this->isTailQueued = false;
            $this->safely($this->readNewMessages(...));
        });
    }

    /**
     * A page connected (Handshake let it in). Runs until it closes
     */
    public function handleClient(WebsocketClient $client, Request $request, Response $response): void
    {
        [$user, $sessionId] = $request->getAttribute(static::class);
        $tab = new Tab($client, $user, $sessionId);
        $this->addTab($tab);

        try {
            while ($message = $client->receive()) {
                $this->receive($tab, $message->buffer());
            }
        } finally {
            $this->removeTab($tab);
        }
    }

    /**
     * @param Tab $tab
     *
     * @return void
     */
    private function addTab($tab)
    {
        $email = $tab->user->email;
        $isFirstTab = empty($this->tabsByEmail[$email]);

        $this->tabs[$tab->getId()] = $tab;
        $this->tabsByEmail[$email][$tab->getId()] = $tab;

        if ($isFirstTab && !isset($this->graceUntil[$email])) {
            $this->safely(fn () => UserInfo::markOnlineNow([$email]));
            $this->queuePresenceUpdate();
        }
        unset($this->graceUntil[$email]);
    }

    /**
     * The page closed: stop its typing, and if it was its person's last one,
     * keep them online a few seconds in case it's a reload or a blip
     *
     * @param Tab $tab
     *
     * @return void
     */
    private function removeTab($tab)
    {
        $email = $tab->user->email;
        $this->stopTyping($tab);

        unset($this->tabs[$tab->getId()], $this->tabsByEmail[$email][$tab->getId()]);
        if (!empty($this->tabsByEmail[$email])) {
            return;
        }
        unset($this->tabsByEmail[$email]);

        $until = microtime(true) + $this->graceSeconds;
        $this->graceUntil[$email] = $until;
        EventLoop::delay($this->graceSeconds, function () use ($email, $until) {
            // Not the clock: a timer can fire a moment early. Only whether a
            // page came back, or a later close started a new grace
            if (($this->graceUntil[$email] ?? null) !== $until || !empty($this->tabsByEmail[$email])) {
                return;
            }
            unset($this->graceUntil[$email]);
            $this->safely(function () use ($email) {
                UserInfo::markGone($email);
                $this->updatePresence();
            });
        });
    }

    /**
     * Act on one message from a page. Anything malformed closes it: the
     * page's own script never sends that
     *
     * @param Tab $tab
     * @param string $text
     *
     * @return void
     */
    private function receive($tab, $text)
    {
        $message = json_decode($text, true);
        $type = is_array($message) ? ($message['type'] ?? null) : null;

        if (!is_string($type) || (!$tab->hasSaidHello && !in_array($type, ['hello', 'ping'], true))) {
            $tab->close(1008, 'Bad message');
            return;
        }

        try {
            $isValid = match ($type) {
                'hello' => $this->sayHello($tab, $message),
                'send' => $this->sendMessage($tab, $message),
                'read' => $this->markRead($tab, $message),
                'older' => $this->sendOlder($tab, $message),
                'typing' => $this->passOnTyping($tab, $message),
                'ping' => $this->answerPing($tab),
                default => false,
            };
        } catch (\PDOException $e) {
            // The page reconnects and sends again; a send it repeats isn't saved twice
            $this->forgetDatabase($e);
            $tab->close(1011, 'Try again');
            return;
        }

        if (!$isValid) {
            $tab->close(1008, 'Bad message');
        }
    }

    /**
     * The page says which message it has last. Send who's here, then catch
     * it up from there in the background
     *
     * @param Tab $tab
     * @param array $message ['after' => int, 'timezone' => string]
     *
     * @return bool
     */
    private function sayHello($tab, $message)
    {
        if ($tab->hasSaidHello || !is_int($message['after'] ?? null) || $message['after'] < 0) {
            return false;
        }

        // The page sends its time zone along, so others can see your local time
        $timezone = $message['timezone'] ?? '';
        if (is_string($timezone) && in_array($timezone, \DateTimeZone::listIdentifiers(), true)) {
            UserInfo::saveTimezone($tab->user->email, $timezone);
        }

        $tab->hasSaidHello = true;
        $tab->cursor = $message['after'];

        if ($this->isPresenceQueued) {
            $this->updatePresence();
        }
        $tab->send(['type' => 'welcome', 'build' => Config::buildId(), 'people' => $this->getPeopleList()]);

        async(fn () => $this->catchUp($tab));

        return true;
    }

    /**
     * Send the page every message it may see after its cursor, a page at a
     * time, up to where the reader has got to; then it's live. Read up to the
     * reader's point, not beyond, so the live stream carries on exactly
     * where catching up stopped
     *
     * @param Tab $tab
     *
     * @return void
     */
    private function catchUp($tab)
    {
        while (!$tab->client->isClosed()) {
            try {
                $chats = Chat::getAfter($tab->user->email, $tab->cursor, $this->tailId);
            } catch (\PDOException $e) {
                $this->forgetDatabase($e);
                $tab->close(1011, 'Try again');
                return;
            }

            if ($chats) {
                $tab->send(['type' => 'chats', 'html' => $this->render($chats, $tab->user, Mention::namesIn($chats))]);
                $tab->cursor = (int) end($chats)['id'];
            }

            if (count($chats) < Chat::HISTORY) {
                $tab->cursor = max($tab->cursor, $this->tailId);
                $tab->isLive = true;
                $tab->send(['type' => 'live']);
                return;
            }

            // Let others have a turn, and the page read what it has
            do {
                delay(0.01);
            } while ($tab->queuedBytes > static::CATCH_UP_QUEUE_BYTES && !$tab->client->isClosed());
        }
    }

    /**
     * Save the message with the chat's rules, tell the page it's saved (or
     * why not), and send it out at once. Its client id makes a repeat of the
     * same send answer with the first one's message
     *
     * @param Tab $tab
     * @param array $message ['clientId' => string, 'body' => string, 'to' => int|null]
     *
     * @return bool
     */
    private function sendMessage($tab, $message)
    {
        $clientId = $message['clientId'] ?? null;
        $body = $message['body'] ?? null;
        $to = $message['to'] ?? null;
        if (!is_string($clientId) || !is_string($body) || ($to !== null && !is_int($to))) {
            return false;
        }

        if (!Sessions::getStillLoggedIn([$tab->sessionId => $tab->user->id])) {
            $tab->close(Tab::CLOSE_LOG_IN_AGAIN, 'Logged out');
            return true;
        }

        if ($to !== null) {
            $this->stopTyping($tab);
        }

        $result = Chat::sendFrom($tab->user, $body, $to, $clientId);

        if (isset($result['error'])) {
            $tab->send(['type' => 'refused', 'clientId' => $clientId, 'status' => $result['status'], 'error' => $result['error']]);
            return true;
        }

        $tab->send(['type' => 'sent', 'clientId' => $clientId, 'id' => $result['id']]);
        $this->readNewMessages();

        return true;
    }

    /**
     * Mark a private chat read up to an id, and tell the person's other
     * pages, so their unread counts go too
     *
     * @param Tab $tab
     * @param array $message ['from' => the other person's id, 'upTo' => int]
     *
     * @return bool
     */
    private function markRead($tab, $message)
    {
        $from = $message['from'] ?? null;
        $upTo = $message['upTo'] ?? null;
        if (!is_int($from) || !is_int($upTo)) {
            return false;
        }

        Chat::markRead($tab->user->email, $from, $upTo);

        foreach ($this->tabsByEmail[$tab->user->email] ?? [] as $otherTab) {
            if ($otherTab !== $tab && $otherTab->hasSaidHello) {
                $otherTab->send(['type' => 'read', 'conversation' => (string) $from, 'upTo' => $upTo]);
            }
        }

        return true;
    }

    /**
     * The page of a tab's messages before an id, when the reader scrolls up
     *
     * @param Tab $tab
     * @param array $message ['requestId' => int, 'before' => int, 'conversation' => "everyone" or an id]
     *
     * @return bool
     */
    private function sendOlder($tab, $message)
    {
        $requestId = $message['requestId'] ?? null;
        $before = $message['before'] ?? null;
        $conversation = (string) ($message['conversation'] ?? '');
        if (!is_int($requestId) || !is_int($before) || ($conversation !== 'everyone' && !ctype_digit($conversation))) {
            return false;
        }

        $chats = Chat::getOlder($tab->user->email, $conversation, $before);
        $html = $chats ? $this->render($chats, $tab->user, Mention::namesIn($chats), true) : '';
        $tab->send(['type' => 'older', 'requestId' => $requestId, 'html' => $html, 'count' => count($chats)]);

        return true;
    }

    /**
     * Tell the person the page is typing to, and nobody else, that it is or
     * has stopped. Only who, never what: the draft stays in the page
     *
     * @param Tab $tab
     * @param array $message ['to' => int, 'isTyping' => bool]
     *
     * @return bool
     */
    private function passOnTyping($tab, $message)
    {
        $to = $message['to'] ?? null;
        $isTyping = $message['isTyping'] ?? null;
        if (!is_int($to) || !is_bool($isTyping)) {
            return false;
        }

        if (!$isTyping || $to === (int) $tab->user->id) {
            $this->stopTyping($tab);
            return true;
        }

        // Typing to someone else now: the first person stops seeing it
        if ($tab->typingTo !== null && $tab->typingTo !== $to) {
            $this->stopTyping($tab);
        }

        $now = microtime(true);
        $tab->typingUntil = $now + static::TYPING_SECONDS;
        if ($tab->typingTo === $to && $now - $tab->typingForwardedAt < static::TYPING_FORWARD_SECONDS) {
            return true;
        }

        $tab->typingTo = $to;
        $tab->typingForwardedAt = $now;
        $this->sendToUser($to, ['type' => 'typing', 'from' => (int) $tab->user->id, 'isTyping' => true]);

        return true;
    }

    /**
     * @param Tab $tab
     *
     * @return bool
     */
    private function answerPing($tab)
    {
        $tab->send(['type' => 'pong']);

        return true;
    }

    /**
     * @param Tab $tab
     *
     * @return void
     */
    private function stopTyping($tab)
    {
        if ($tab->typingTo === null) {
            return;
        }

        $this->sendToUser($tab->typingTo, ['type' => 'typing', 'from' => (int) $tab->user->id, 'isTyping' => false]);
        $tab->typingTo = null;
    }

    /**
     * Stop "typing" for pages that went quiet without saying so
     *
     * @return void
     */
    private function expireTyping()
    {
        $now = microtime(true);
        foreach ($this->tabs as $tab) {
            if ($tab->typingTo !== null && $tab->typingUntil < $now) {
                $this->stopTyping($tab);
            }
        }
    }

    /**
     * @param int $userId
     * @param array $message
     *
     * @return void
     */
    private function sendToUser($userId, $message)
    {
        foreach ($this->tabs as $tab) {
            if ((int) $tab->user->id === $userId && $tab->hasSaidHello) {
                $tab->send($message);
            }
        }
    }

    /**
     * Read the messages saved since the last read, in id order, and send
     * each to the live pages that may see it: the open room's to everyone,
     * a private one to its sender's and recipient's pages only. Stops at a
     * missing id for a few seconds (GAP_SECONDS), so a send that took an
     * earlier id but commits later isn't skipped
     *
     * @return void
     */
    private function readNewMessages()
    {
        $ids = Chat::getIdsAfter($this->tailId, static::TAIL_BATCH);
        $readIds = [];
        $lastId = $this->tailId;

        foreach ($ids as $id) {
            if ($id !== $lastId + 1) {
                $this->gapSince ??= microtime(true);
                if (microtime(true) - $this->gapSince < static::GAP_SECONDS) {
                    break;
                }
            }
            $this->gapSince = null;
            $readIds[] = $id;
            $lastId = $id;
        }

        if (!$readIds) {
            return;
        }

        $chats = Chat::getByIds($readIds);
        $this->tailId = $lastId;
        $this->sendToLivePages($chats);
    }

    /**
     * @param array $chats Oldest first
     *
     * @return void
     */
    private function sendToLivePages($chats)
    {
        $chatsByTab = [];
        foreach ($chats as $chat) {
            $readers = $chat['to_email'] === null
                ? $this->tabs
                : ($this->tabsByEmail[$chat['email']] ?? []) + ($this->tabsByEmail[$chat['to_email']] ?? []);

            foreach ($readers as $tab) {
                if ($tab->isLive && (int) $chat['id'] > $tab->cursor) {
                    $chatsByTab[$tab->getId()][] = $chat;
                }
            }
        }

        // Every live page now has everything up to here, whether or not any of it was for them
        foreach ($this->tabs as $tab) {
            if ($tab->isLive) {
                $tab->cursor = max($tab->cursor, $this->tailId);
            }
        }

        if (!$chatsByTab) {
            return;
        }

        $mentionNames = Mention::namesIn($chats);
        foreach ($chatsByTab as $tabId => $tabChats) {
            $tab = $this->tabs[$tabId];
            $tab->send(['type' => 'chats', 'html' => $this->render($tabChats, $tab->user, $mentionNames)]);
        }
    }

    /**
     * The messages as the page shows them, for this reader
     *
     * @param array $chats
     * @param \App\Models\User $reader
     * @param array $mentionNames Mention::namesIn of these messages, or more
     * @param bool $isHistory Older messages appear at once, without the new-message ease-in
     *
     * @return string
     */
    private function render($chats, $reader, $mentionNames, $isHistory = false)
    {
        return View::getTemplate('App/chat.html', [
            'chats' => $chats,
            'current_user' => $reader,
            'mention_names' => $mentionNames,
            'initial' => $isHistory,
        ]);
    }

    /**
     * Look for presence changes on the next turn of the loop, once however
     * many pages open meanwhile
     *
     * @return void
     */
    private function queuePresenceUpdate()
    {
        if ($this->isPresenceQueued) {
            return;
        }
        $this->isPresenceQueued = true;
        EventLoop::defer(fn () => $this->safely($this->updatePresence(...)));
    }

    /**
     * Tell every page who came online and who went. Online is: a socket
     * open (or closed less than the grace period ago), or online as the
     * people list counts it (UserInfo::getOnlineEmails): a page on the HTTP
     * fallback, a demo person in the room, or MapChat
     *
     * @return void
     */
    private function updatePresence()
    {
        $this->isPresenceQueued = false;

        $online = array_fill_keys(array_merge(
            array_keys($this->tabsByEmail),
            array_keys($this->graceUntil),
            UserInfo::getOnlineEmails()
        ), true);
        $arrived = array_keys(array_diff_key($online, $this->announced));
        $left = array_keys(array_diff_key($this->announced, $online));
        if (!$arrived && !$left) {
            return;
        }

        $people = array_column(UserInfo::getPeople(array_merge($arrived, $left)), null, 'email');

        foreach ($arrived as $email) {
            if (isset($people[$email])) {
                $this->announced[$email] = $people[$email];
                $this->sendToAll(['type' => 'presence', 'person' => static::shown($people[$email], true)]);
            }
        }
        foreach ($left as $email) {
            // A deleted guest has no row any more; the page drops them by id
            $person = $people[$email] ?? $this->announced[$email] + ['is_deleted' => 1];
            unset($this->announced[$email]);
            $this->sendToAll(['type' => 'presence', 'person' => static::shown($person, false)]);
        }
    }

    /**
     * The people list as /app/get-people gives it, with online as this
     * process has announced it, so the changes it sends later line up
     *
     * @return array
     */
    private function getPeopleList()
    {
        $onlineIds = array_flip(array_column($this->announced, 'id'));
        $people = [];

        foreach (UserInfo::getRecentPeople() as $person) {
            $isOnline = isset($onlineIds[$person['id']]);
            unset($onlineIds[$person['id']]);
            $people[] = static::shown($person, $isOnline);
        }

        // Online here but not in that list (it counts online by the database's clock)
        foreach ($this->announced as $person) {
            if (isset($onlineIds[$person['id']])) {
                $people[] = static::shown($person, true);
            }
        }

        return $people;
    }

    /**
     * A people row as pages get it: never the email
     *
     * @param array $person
     * @param bool $isOnline
     *
     * @return array
     */
    private static function shown($person, $isOnline)
    {
        unset($person['email']);
        $person['is_online'] = $isOnline ? 1 : 0;

        return $person;
    }

    /**
     * @param array $message
     *
     * @return void
     */
    private function sendToAll($message)
    {
        foreach ($this->tabs as $tab) {
            if ($tab->hasSaidHello) {
                $tab->send($message);
            }
        }
    }

    /**
     * Close the pages whose session ended: logged out, expired, or their
     * guest account deleted. They're sent to the login page
     *
     * @return void
     */
    private function closeLoggedOutTabs()
    {
        $userIdsBySession = [];
        foreach ($this->tabs as $tab) {
            $userIdsBySession[$tab->sessionId] = (int) $tab->user->id;
        }

        $stillLoggedIn = array_flip(Sessions::getStillLoggedIn($userIdsBySession));
        foreach ($this->tabs as $tab) {
            if (!isset($stillLoggedIn[$tab->sessionId])) {
                $tab->close(Tab::CLOSE_LOG_IN_AGAIN, 'Logged out');
            }
        }
    }

    /**
     * @return array
     */
    private function getSessionIds()
    {
        return array_values(array_unique(array_map(fn ($tab) => $tab->sessionId, $this->tabs)));
    }

    /**
     * Run timed or signalled work; a database error is logged and the
     * connection made again next time, so one failure doesn't stop the loop
     *
     * @param callable $work
     *
     * @return void
     */
    private function safely($work)
    {
        try {
            $work();
        } catch (\PDOException $e) {
            $this->forgetDatabase($e);
        }
    }

    /**
     * @param \PDOException $e
     *
     * @return void
     */
    private function forgetDatabase($e)
    {
        error_log('Chat socket: database error, reconnecting: ' . $e->getMessage());
        \Core\Model::disconnectDB();
    }
}

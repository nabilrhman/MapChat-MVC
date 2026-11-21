<?php

namespace App\ChatSocket;

use Amp\Http\Server\Request;
use Amp\Http\Server\Response;
use Amp\Websocket\Server\WebsocketClientHandler;
use Amp\Websocket\WebsocketClient;
use App\Config;
use App\Messages\Conversation;
use App\Messages\Mention;
use App\Messages\MessageText;
use App\Messages\MessagesForReader;
use App\Models\Chat;
use App\Models\User;
use App\Models\Whereabouts;
use Core\DatabaseSessionHandler;
use Core\View;
use Revolt\EventLoop;
use function Amp\async;
use function Amp\delay;

/**
 * The chat over WebSockets, one per open chat page. Messages are read from
 * the database in id order, by one reader for everyone (NewMessageReader),
 * and each goes to the pages allowed to see it. A page that (re)connects
 * says which message it has last; it's caught up from the database from
 * there, then joins the live stream, so it gets every message once and in
 * order
 *
 * Messages a page sends are saved with the same rules as the HTTP chat
 * (Chat::sendFrom), and come back to it through the stream like everyone
 * else's. Presence (who's online) and typing go out as they change
 *
 * Everything here runs in one process and one event loop. Database calls
 * block it for their few milliseconds; nothing else waits between reading
 * the state and changing it, so no two handlers interleave
 */
class Hub implements WebsocketClientHandler
{
    /** The longest a new message waits to be read when no Signal arrives */
    const TAIL_SECONDS = 0.5;

    /** Most messages read in one go */
    const TAIL_BATCH = 500;

    /** How often to look for presence changes not seen as they happen (demo people, polling tabs) */
    const PRESENCE_SECONDS = 5;

    /** How often to mark everyone with a socket open as online in the database (Whereabouts::ONLINE_SECONDS is 60) */
    const MARK_ONLINE_SECONDS = 30;

    /** How often to check that each page's session is still logged in */
    const SESSION_CHECK_SECONDS = 15;

    /** How often to keep open pages' sessions from expiring (DatabaseSessionHandler::touch) */
    const SESSION_TOUCH_SECONDS = 600;

    /**
     * Pages of older messages one person gets at once, then each second.
     * Each is a query and a render of a page that hold up every other page
     */
    const OLDER_BURST = 10;
    const OLDER_PER_SECOND = 1;

    /** Pages of catching up one person gets at once (every tab after a restart), then each second */
    const CATCH_UP_BURST = 30;
    const CATCH_UP_PER_SECOND = 2;

    /**
     * A page further behind than this many pages skips to the newest page,
     * and loads older ones itself as the reader scrolls up
     */
    const CATCH_UP_PAGES = 10;

    /** How often to forget the budgets of people who've used none lately */
    const BUDGET_FORGET_SECONDS = 60;

    /** @var Tab[] By connection id */
    private array $tabs = [];

    /** @var Tab[][] By email, then connection id */
    private array $tabsByEmail = [];

    /** Reads new messages; every live page has everything up to its last read id */
    private NewMessageReader $newMessages;

    private bool $isTailQueued = false;

    /** @var RateBudget[] Each person's pages of older messages, by email */
    private array $olderBudgets = [];

    /** @var RateBudget[] Each person's pages of catching up, by email */
    private array $catchUpBudgets = [];

    /** Who's announced as online, as people rows by email */
    private array $announced = [];

    /** When each person whose last page closed stops counting as online, by email */
    private array $graceUntil = [];

    private bool $isPresenceQueued = false;

    /** How long a closed last page keeps its person online, for a reload or a network blip */
    private readonly float $graceSeconds;

    private readonly float $sessionCheckSeconds;

    /**
     * Read the settings the tests shorten
     */
    public function __construct()
    {
        $this->graceSeconds = (float) Config::get('CHAT_PRESENCE_GRACE_SECONDS', 10);
        $this->sessionCheckSeconds = (float) Config::get('CHAT_SESSION_CHECK_SECONDS', static::SESSION_CHECK_SECONDS);
    }

    /**
     * Start reading messages and keeping presence. The reader starts at the
     * newest message: pages that connect say where they are themselves
     */
    public function start(): void
    {
        $this->newMessages = new NewMessageReader(Chat::getNewestId());
        foreach (Whereabouts::getPeople(Whereabouts::getOnlineEmails()) as $person) {
            $this->announced[$person['email']] = $person;
        }

        EventLoop::repeat(static::TAIL_SECONDS, fn () => $this->safely($this->readNewMessages(...)));
        EventLoop::repeat(1, fn () => $this->safely($this->expireTyping(...)));
        EventLoop::repeat(static::PRESENCE_SECONDS, fn () => $this->safely($this->updatePresence(...)));
        EventLoop::repeat(static::MARK_ONLINE_SECONDS, fn () => $this->safely(fn () => Whereabouts::markOnlineNow(array_keys($this->tabsByEmail))));
        EventLoop::repeat($this->sessionCheckSeconds, fn () => $this->safely($this->closeLoggedOutTabs(...)));
        EventLoop::repeat(static::SESSION_TOUCH_SECONDS, fn () => $this->safely(fn () => DatabaseSessionHandler::touch($this->getSessionIds())));
        EventLoop::repeat(static::BUDGET_FORGET_SECONDS, $this->forgetFullBudgets(...));
    }

    /**
     * Close every page with "restarting" (1012); they reconnect to the next process
     */
    public function closeAll(): void
    {
        foreach ($this->tabs as $tab) {
            $tab->close(1012, 'Restarting');
        }
    }

    /**
     * How many pages are open, for the Handshake's limit
     */
    public function countTabs(): int
    {
        return count($this->tabs);
    }

    /**
     * How many pages one person has open, for the Handshake's limit
     */
    public function countTabsOf(string $email): int
    {
        return count($this->tabsByEmail[$email] ?? []);
    }

    /**
     * A message was saved (Signal): read it now rather than on the next round
     */
    public function onNewMessages(): void
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
     * The chat worker is writing someone a private reply as MapChat or a
     * demo person, or stopped without one (Signal::typing): pass it to that
     * person's pages, as a page's own typing would be
     *
     * @param string $datagram As Signal::typing sends it
     */
    public function onWorkerTyping(string $datagram): void
    {
        $typing = json_decode($datagram, true)['typing'] ?? null;
        if (!is_string($typing['from'] ?? null) || !is_string($typing['to'] ?? null) || !is_bool($typing['is_typing'] ?? null)) {
            return;
        }
        if (empty($this->tabsByEmail[$typing['to']])) {
            return;
        }

        $this->safely(function () use ($typing) {
            $speaker = User::findByEmail($typing['from']);
            if (!$speaker) {
                return;
            }
            $this->sendToTabs(
                $this->tabsByEmail[$typing['to']] ?? [],
                ['type' => MessageToPage::Typing, 'from' => $speaker->id, 'is_typing' => $typing['is_typing']]
            );
        });
    }

    /**
     * A page connected (Handshake let it in). Runs until it closes
     */
    public function handleClient(WebsocketClient $client, Request $request, Response $response): void
    {
        [$user, $sessionId] = $request->getAttribute(static::class);

        // The Handshake checked the limits too, but handshakes at the same
        // time all pass it before any of their pages is added here
        if ($this->countTabs() >= Handshake::MAX_TABS || $this->countTabsOf($user->email) >= Handshake::MAX_TABS_PER_USER) {
            $client->close(1008, 'Too many tabs');
            return;
        }

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
     * Take a page in. Its person's first page shows them online
     */
    private function addTab(Tab $tab): void
    {
        $email = $tab->user->email;
        $isFirstTab = empty($this->tabsByEmail[$email]);

        $this->tabs[$tab->getId()] = $tab;
        $this->tabsByEmail[$email][$tab->getId()] = $tab;

        if ($isFirstTab && !isset($this->graceUntil[$email])) {
            $this->safely(fn () => Whereabouts::markOnlineNow([$email]));
            $this->queuePresenceUpdate();
        }
        unset($this->graceUntil[$email]);
    }

    /**
     * The page closed: stop its typing, and if it was its person's last one,
     * keep them online a few seconds in case it's a reload or a blip
     */
    private function removeTab(Tab $tab): void
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
                Whereabouts::markGone($email);
                $this->updatePresence();
            });
        });
    }

    /**
     * Act on one message from a page. Anything malformed closes it: the
     * page's own script never sends that
     *
     * @param string $text The message as the page sent it, JSON
     */
    private function receive(Tab $tab, string $text): void
    {
        $message = json_decode($text, true);
        $typeName = is_array($message) ? ($message['type'] ?? null) : null;
        $type = is_string($typeName) ? MessageFromPage::tryFrom($typeName) : null;

        if ($type === null || (!$tab->hasSaidHello && !$type->isAllowedBeforeHello())) {
            $tab->close(1008, 'Bad message');
            return;
        }

        try {
            $isValid = match ($type) {
                MessageFromPage::Hello => $this->sayHello($tab, $message),
                MessageFromPage::Send => $this->sendMessage($tab, $message),
                MessageFromPage::Read => $this->markRead($tab, $message),
                MessageFromPage::Older => $this->sendOlder($tab, $message),
                MessageFromPage::Typing => $this->passOnTyping($tab, $message),
                MessageFromPage::Ping => $this->answerPing($tab),
            };
        } catch (\Throwable $e) {
            // The page reconnects and sends again; a send it repeats isn't saved twice
            $this->recoverFrom($e);
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
     * @param array $message ['after' => int, 'timezone' => string]
     *
     * @return bool Whether the message was well formed
     */
    private function sayHello(Tab $tab, array $message): bool
    {
        if ($tab->hasSaidHello || !is_int($message['after'] ?? null) || $message['after'] < 0) {
            return false;
        }

        // So others can see your local time
        Whereabouts::saveTimezone($tab->user->email, $message['timezone'] ?? null);

        $tab->sayHello($message['after']);

        if ($this->isPresenceQueued) {
            $this->updatePresence();
        }
        $tab->send(['type' => MessageToPage::Welcome, 'build' => Config::buildId(), 'people' => $this->getPeopleList()]);

        async(fn () => $this->catchUp($tab));

        return true;
    }

    /**
     * Send the page every message it may see after its cursor, a page at a
     * time, up to where the reader has got to; then it's live. Read up to the
     * reader's point, not beyond, so the live stream carries on exactly
     * where catching up stopped. Runs on its own, so a failure here ends
     * only this page's socket
     */
    private function catchUp(Tab $tab): void
    {
        try {
            // A page with no messages yet starts at the newest, not the first message ever
            if ($tab->cursor === 0) {
                $this->skipToNewestPage($tab);
            }

            $pagesSent = 0;
            while (!$tab->isClosed()) {
                if ($pagesSent === static::CATCH_UP_PAGES) {
                    $this->skipToNewestPage($tab);
                }
                if (!static::waitForBudget($this->catchUpBudgetOf($tab->user->email), $tab)) {
                    return;
                }

                $chats = Chat::getAfter($tab->user->email, $tab->cursor, $this->newMessages->getLastReadId());
                if ($chats) {
                    $tab->send(['type' => MessageToPage::Chats, 'html' => $this->render($chats, $tab->user, Mention::namesIn($chats))]);
                    $tab->moveCursorTo(end($chats)['id']);
                }

                if (count($chats) < Chat::HISTORY) {
                    $tab->goLive($this->newMessages->getLastReadId());
                    return;
                }
                $pagesSent++;

                // Let others have a turn, and the page read what it has
                do {
                    delay(0.01);
                } while ($tab->isBacklogged() && !$tab->isClosed());
            }
        } catch (\Throwable $e) {
            $this->recoverFrom($e);
            $tab->close(1011, 'Try again');
        }
    }

    /**
     * Move the page's cursor to just before the newest page of messages it
     * may see, so catching up sends that page and no more
     */
    private function skipToNewestPage(Tab $tab): void
    {
        $newestPage = Chat::getBefore($tab->user->email, $this->newMessages->getLastReadId() + 1);
        if ($newestPage) {
            $tab->moveCursorTo(array_first($newestPage)['id'] - 1);
        }
    }

    /**
     * Save the message with the chat's rules, tell the page it's saved (or
     * why not), and send it out at once. Its client id makes a repeat of the
     * same send answer with the first one's message
     *
     * @param array $message ['client_id' => string, 'body' => string, 'to' => int|null,
     *                        'original' => what was typed when body is its translation, or null]
     *
     * @return bool Whether the message was well formed
     */
    private function sendMessage(Tab $tab, array $message): bool
    {
        $clientId = $message['client_id'] ?? null;
        $body = $message['body'] ?? null;
        $to = $message['to'] ?? null;
        $originalBody = $message['original'] ?? null;
        if (!is_string($clientId) || !is_string($body) || ($to !== null && !is_int($to))
            || ($originalBody !== null && !is_string($originalBody))) {
            return false;
        }

        if (!Sessions::getStillLoggedIn([$tab->sessionId => $tab->user->id], [$tab->sessionId => $tab->user->signInStamp()])) {
            $tab->close(Tab::CLOSE_LOG_IN_AGAIN, 'Logged out');
            return true;
        }

        if ($to !== null) {
            $this->stopTyping($tab);
        }

        $result = Chat::sendFrom($tab->user, new MessageText($body, $originalBody), $to, $clientId);

        if (!$result->isSent()) {
            $tab->send(['type' => MessageToPage::Refused, 'client_id' => $clientId, 'status' => $result->status, 'error' => $result->error]);
            return true;
        }

        $tab->send(['type' => MessageToPage::Sent, 'client_id' => $clientId, 'id' => $result->id]);
        $this->readNewMessages();

        return true;
    }

    /**
     * Mark a private chat read up to an id, and tell the person's other
     * pages, so their unread counts go too
     *
     * @param array $message ['conversation' => the other person's id, as a string, 'up_to' => int]
     *
     * @return bool Whether the message was well formed
     */
    private function markRead(Tab $tab, array $message): bool
    {
        $conversation = Conversation::privateFromPageName($message['conversation'] ?? null);
        $upTo = $message['up_to'] ?? null;
        if ($conversation === null || !is_int($upTo)) {
            return false;
        }

        Chat::markRead($tab->user->email, $conversation->partnerId, $upTo);

        $otherTabs = array_filter($this->tabsByEmail[$tab->user->email] ?? [], fn (Tab $otherTab): bool => $otherTab !== $tab);
        $this->sendToTabs(
            $otherTabs,
            ['type' => MessageToPage::Read, 'conversation' => $conversation->pageName(), 'up_to' => $upTo]
        );

        return true;
    }

    /**
     * The page of a tab's messages before an id, when the reader scrolls up
     *
     * @param array $message ['request_id' => int, 'before' => int, 'conversation' => "everyone" or an id]
     *
     * @return bool Whether the message was well formed
     */
    private function sendOlder(Tab $tab, array $message): bool
    {
        $requestId = $message['request_id'] ?? null;
        $before = $message['before'] ?? null;
        $pageName = $message['conversation'] ?? null;
        $conversation = is_string($pageName) ? Conversation::fromPageName($pageName) : null;
        if (!is_int($requestId) || !is_int($before) || $conversation === null) {
            return false;
        }

        // One at a time, as the page's own script asks: another meanwhile goes unanswered
        if ($tab->isFetchingOlder) {
            return true;
        }
        $tab->isFetchingOlder = true;
        async(fn () => $this->answerOlder($tab, $requestId, $conversation, $before));

        return true;
    }

    /**
     * Send the page of older messages once its person's budget allows,
     * while the page's other messages go on being answered
     *
     * @param int $requestId The page's own id for the request, sent back with the answer
     */
    private function answerOlder(Tab $tab, int $requestId, Conversation $conversation, int $before): void
    {
        try {
            if (!static::waitForBudget($this->olderBudgetOf($tab->user->email), $tab)) {
                return;
            }

            $chats = Chat::getOlder($tab->user->email, $conversation, $before);
            $html = $chats ? $this->render($chats, $tab->user, Mention::namesIn($chats), isHistory: true) : '';
            $tab->send(['type' => MessageToPage::Older, 'request_id' => $requestId, 'html' => $html, 'count' => count($chats)]);
        } catch (\Throwable $e) {
            $this->recoverFrom($e);
            $tab->close(1011, 'Try again');
        } finally {
            $tab->isFetchingOlder = false;
        }
    }

    /**
     * Wait until the budget has one to spend, and spend it
     *
     * @return bool False if the page closed meanwhile, and nothing was spent
     */
    private static function waitForBudget(RateBudget $budget, Tab $tab): bool
    {
        while (!$tab->isClosed()) {
            if ($budget->trySpend()) {
                return true;
            }
            delay($budget->secondsUntilNext());
        }

        return false;
    }

    /**
     * One person's budget for pages of older messages
     */
    private function olderBudgetOf(string $email): RateBudget
    {
        return $this->olderBudgets[$email] ??= new RateBudget(static::OLDER_BURST, static::OLDER_PER_SECOND);
    }

    /**
     * One person's budget for pages of catching up
     */
    private function catchUpBudgetOf(string $email): RateBudget
    {
        return $this->catchUpBudgets[$email] ??= new RateBudget(static::CATCH_UP_BURST, static::CATCH_UP_PER_SECOND);
    }

    /**
     * Forget the budgets that have refilled: a new one is the same, and
     * people who've gone needn't be kept
     */
    private function forgetFullBudgets(): void
    {
        $this->olderBudgets = array_filter($this->olderBudgets, fn (RateBudget $budget): bool => !$budget->isFull());
        $this->catchUpBudgets = array_filter($this->catchUpBudgets, fn (RateBudget $budget): bool => !$budget->isFull());
    }

    /**
     * Tell the person the page is typing to, and nobody else, that it is or
     * has stopped. Only who, never what: the draft stays in the page
     *
     * @param array $message ['to' => int, 'is_typing' => bool]
     *
     * @return bool Whether the message was well formed
     */
    private function passOnTyping(Tab $tab, array $message): bool
    {
        $to = $message['to'] ?? null;
        $isTyping = $message['is_typing'] ?? null;
        if (!is_int($to) || !is_bool($isTyping)) {
            return false;
        }

        if (!$isTyping || $to === $tab->user->id) {
            $this->stopTyping($tab);
            return true;
        }

        // Typing to someone else now: the first person stops seeing it
        if ($tab->typingTo !== null && $tab->typingTo !== $to) {
            $this->stopTyping($tab);
        }

        if ($tab->noteTyping($to)) {
            $this->sendToUser($to, ['type' => MessageToPage::Typing, 'from' => $tab->user->id, 'is_typing' => true]);
        }

        return true;
    }

    /**
     * Answer the page's ping, which it uses to tell a dead connection
     *
     * @return bool Always true: a ping is always well formed
     */
    private function answerPing(Tab $tab): bool
    {
        $tab->send(['type' => MessageToPage::Pong]);

        return true;
    }

    /**
     * Tell whoever the page was typing to that it stopped
     */
    private function stopTyping(Tab $tab): void
    {
        $wasTypingTo = $tab->stopTyping();
        if ($wasTypingTo !== null) {
            $this->sendToUser($wasTypingTo, ['type' => MessageToPage::Typing, 'from' => $tab->user->id, 'is_typing' => false]);
        }
    }

    /**
     * Stop "typing" for pages that went quiet without saying so
     */
    private function expireTyping(): void
    {
        foreach ($this->tabs as $tab) {
            if ($tab->hasTypingLapsed()) {
                $this->stopTyping($tab);
            }
        }
    }

    /**
     * Send a message to every page of one person that said hello
     *
     * @param array<string, mixed> $message
     */
    private function sendToUser(int $userId, array $message): void
    {
        $this->sendToTabs(array_filter($this->tabs, fn (Tab $tab): bool => $tab->user->id === $userId), $message);
    }

    /**
     * Send a message to each of these pages that said hello; the rest
     * aren't ready for it
     *
     * @param Tab[] $tabs
     * @param array<string, mixed> $message
     */
    private function sendToTabs(array $tabs, array $message): void
    {
        foreach ($tabs as $tab) {
            if ($tab->hasSaidHello) {
                $tab->send($message);
            }
        }
    }

    /**
     * Read the messages saved since the last read, in id order, and send
     * each to the live pages that may see it: the open room's to everyone,
     * a private one to its sender's and recipient's pages only
     */
    private function readNewMessages(): void
    {
        $readIds = $this->newMessages->readNewIds(static::TAIL_BATCH);
        if ($readIds) {
            $this->sendToLivePages(Chat::getByIds($readIds));
        }
    }

    /**
     * Send newly read messages to the live pages that may see each one
     *
     * @param array[] $chats Oldest first
     */
    private function sendToLivePages(array $chats): void
    {
        $chatsByTab = [];
        foreach ($chats as $chat) {
            $readers = $chat['to_email'] === null
                ? $this->tabs
                : ($this->tabsByEmail[$chat['email']] ?? []) + ($this->tabsByEmail[$chat['to_email']] ?? []);

            foreach ($readers as $tab) {
                if ($tab->isLive && $chat['id'] > $tab->cursor) {
                    $chatsByTab[$tab->getId()][] = $chat;
                }
            }
        }

        // Every live page now has everything up to here, whether or not any of it was for them
        foreach ($this->tabs as $tab) {
            if ($tab->isLive) {
                $tab->moveCursorTo($this->newMessages->getLastReadId());
            }
        }

        if (!$chatsByTab) {
            return;
        }

        $mentionNames = Mention::namesIn($chats);
        foreach ($chatsByTab as $tabId => $tabChats) {
            $tab = $this->tabs[$tabId];
            $tab->send(['type' => MessageToPage::Chats, 'html' => $this->render($tabChats, $tab->user, $mentionNames)]);
        }
    }

    /**
     * The messages as the page shows them, for this reader
     *
     * @param string[] $mentionNames Mention::namesIn of these messages, or more
     * @param bool $isHistory Older messages appear at once, without the new-message ease-in
     * @param array[] $chats Message rows
     */
    private function render(array $chats, User $reader, array $mentionNames, bool $isHistory = false): string
    {
        return View::getTemplate('Room/chat.html', [
            'chats' => MessagesForReader::rowsOf($chats, $reader),
            'current_user' => $reader,
            'mention_names' => $mentionNames,
            'initial' => $isHistory,
        ]);
    }

    /**
     * Look for presence changes on the next turn of the loop, once however
     * many pages open meanwhile
     */
    private function queuePresenceUpdate(): void
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
     * people list counts it (Whereabouts::getOnlineEmails): a page on the HTTP
     * fallback, a demo person in the room, or MapChat
     */
    private function updatePresence(): void
    {
        $this->isPresenceQueued = false;

        $online = array_fill_keys(array_merge(
            array_keys($this->tabsByEmail),
            array_keys($this->graceUntil),
            Whereabouts::getOnlineEmails()
        ), true);
        $arrived = array_keys(array_diff_key($online, $this->announced));
        $left = array_keys(array_diff_key($this->announced, $online));
        if (!$arrived && !$left) {
            return;
        }

        $people = array_column(Whereabouts::getPeople(array_merge($arrived, $left)), null, 'email');

        foreach ($arrived as $email) {
            if (isset($people[$email])) {
                $this->announced[$email] = $people[$email];
                $this->sendToTabs($this->tabs, ['type' => MessageToPage::Presence, 'person' => static::personForPages($people[$email], true)]);
            }
        }
        foreach ($left as $email) {
            // A deleted guest has no row any more; the page drops them by id
            $person = $people[$email] ?? $this->announced[$email] + ['is_deleted' => true];
            unset($this->announced[$email]);
            $this->sendToTabs($this->tabs, ['type' => MessageToPage::Presence, 'person' => static::personForPages($person, false)]);
        }
    }

    /**
     * The people list as /app/get-people gives it, with online as this
     * process has announced it, so the changes it sends later line up
     *
     * @return array[] People rows, as personForPages gives them
     */
    private function getPeopleList(): array
    {
        $onlineIds = array_flip(array_column($this->announced, 'id'));
        $people = [];

        foreach (Whereabouts::getRecentPeople() as $person) {
            $isOnline = isset($onlineIds[$person['id']]);
            unset($onlineIds[$person['id']]);
            $people[] = static::personForPages($person, $isOnline);
        }

        // Online here but not in that list (it counts online by the database's clock)
        foreach ($this->announced as $person) {
            if (isset($onlineIds[$person['id']])) {
                $people[] = static::personForPages($person, true);
            }
        }

        return $people;
    }

    /**
     * A people row as pages get it: with is_online, and never the email
     *
     * @param array<string, mixed> $person
     *
     * @return array<string, mixed>
     */
    private static function personForPages(array $person, bool $isOnline): array
    {
        unset($person['email']);
        $person['is_online'] = $isOnline;

        return $person;
    }

    /**
     * Close the pages whose session ended: logged out, expired, or their
     * guest account's day is up. They're sent to the login page
     */
    private function closeLoggedOutTabs(): void
    {
        $userIdsBySession = [];
        $stampsBySession = [];
        foreach ($this->tabs as $tab) {
            $userIdsBySession[$tab->sessionId] = $tab->user->id;
            // As it was when the page connected: a password changed since ends it
            $stampsBySession[$tab->sessionId] = $tab->user->signInStamp();
        }

        $stillLoggedIn = array_flip(Sessions::getStillLoggedIn($userIdsBySession, $stampsBySession));
        foreach ($this->tabs as $tab) {
            if (!isset($stillLoggedIn[$tab->sessionId])) {
                $tab->close(Tab::CLOSE_LOG_IN_AGAIN, 'Logged out');
            }
        }
    }

    /**
     * The open pages' login sessions, each once
     *
     * @return string[]
     */
    private function getSessionIds(): array
    {
        return array_values(array_unique(array_map(fn (Tab $tab): string => $tab->sessionId, $this->tabs)));
    }

    /**
     * Run timed or signalled work; a failure is logged, so one doesn't stop the loop
     */
    private function safely(callable $work): void
    {
        try {
            $work();
        } catch (\Throwable $e) {
            $this->recoverFrom($e);
        }
    }

    /**
     * Log a failure. A database error also drops the connection, so the
     * next query makes a new one
     */
    private function recoverFrom(\Throwable $e): void
    {
        if ($e instanceof \PDOException) {
            error_log('Chat socket: database error, reconnecting: ' . $e->getMessage());
            \Core\Model::disconnectDB();
            return;
        }

        error_log('Chat socket: ' . $e);
    }
}

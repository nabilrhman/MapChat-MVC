<?php

namespace App\ChatSocket;

use Amp\Websocket\WebsocketClient;
use Amp\Websocket\WebsocketClosedException;
use App\Models\User;
use function Amp\async;

/**
 * One open chat page's WebSocket, and where that page is in the chat: which
 * messages it has, and who it's typing to
 */
class Tab
{
    /**
     * A page that lets more than this pile up unread is too slow: it's cut,
     * and catches up from where it got to when it reconnects
     */
    const MAX_QUEUED_BYTES = 1048576;

    /** A catching-up page waits while more than this is queued for it */
    const CATCH_UP_QUEUE_BYTES = 262144;

    /** Close codes the page acts on (public/js/messages/messages.js) */
    const CLOSE_LOG_IN_AGAIN = 4401;
    const CLOSE_TOO_SLOW = 4408;

    /** How long "typing" holds without a fresh word from the page */
    const TYPING_SECONDS = 6;

    /** "Typing" is passed on at most this often per page */
    const TYPING_FORWARD_SECONDS = 1;

    /** Whether the page said hello, and so takes part */
    public private(set) bool $hasSaidHello = false;

    /**
     * Whether it has every message up to the Hub's reading point, so new
     * ones go straight to it. Until then it's catching up
     */
    public private(set) bool $isLive = false;

    /** The newest message id the page has been given, or asked to start after */
    public private(set) int $cursor = 0;

    /** Who the page says its person is typing to, or null */
    public private(set) ?int $typingTo = null;

    /** Until when that holds, and when it was last passed on, as microtime */
    private float $typingUntil = 0;
    private float $typingForwardedAt = 0;

    /** Messages waiting to be written, in order, and their size */
    private array $queued = [];
    private int $queuedBytes = 0;
    private bool $isWriting = false;

    /**
     * A page the Handshake let in
     *
     * @param string $sessionId The login session it came with
     */
    public function __construct(
        public readonly WebsocketClient $client,
        public readonly User $user,
        public readonly string $sessionId,
    ) {
    }

    /**
     * The connection's id, unique while it's open
     */
    public function getId(): int
    {
        return $this->client->getId();
    }

    /**
     * Whether the connection has closed
     */
    public function isClosed(): bool
    {
        return $this->client->isClosed();
    }

    /**
     * The page said hello: it takes part from now, and has the messages up
     * to the one it named
     */
    public function sayHello(int $afterId): void
    {
        $this->hasSaidHello = true;
        $this->cursor = $afterId;
    }

    /**
     * The page has been sent the messages up to this id
     */
    public function moveCursorTo(int $id): void
    {
        $this->cursor = max($this->cursor, $id);
    }

    /**
     * The page has caught up to the Hub's reading point: new messages go
     * straight to it from now
     *
     * @param int $lastReadId The Hub's reading point
     */
    public function goLive(int $lastReadId): void
    {
        $this->moveCursorTo($lastReadId);
        $this->isLive = true;
        $this->send(['type' => MessageToPage::Live]);
    }

    /**
     * Whether so much is queued for the page that catching up should wait
     */
    public function isBacklogged(): bool
    {
        return $this->queuedBytes > static::CATCH_UP_QUEUE_BYTES;
    }

    /**
     * The page's person is typing to someone. Holds for TYPING_SECONDS
     *
     * @param int $toUserId Who they're typing to
     *
     * @return bool Whether to tell that person now: at most every TYPING_FORWARD_SECONDS
     */
    public function noteTyping(int $toUserId): bool
    {
        $now = microtime(true);
        $this->typingUntil = $now + static::TYPING_SECONDS;
        if ($this->typingTo === $toUserId && $now - $this->typingForwardedAt < static::TYPING_FORWARD_SECONDS) {
            return false;
        }

        $this->typingTo = $toUserId;
        $this->typingForwardedAt = $now;

        return true;
    }

    /**
     * The page's person stopped typing
     *
     * @return int|null Who they were typing to, to tell them; null if nobody
     */
    public function stopTyping(): ?int
    {
        $wasTypingTo = $this->typingTo;
        $this->typingTo = null;

        return $wasTypingTo;
    }

    /**
     * Whether the page is typing but went quiet for TYPING_SECONDS without saying so
     */
    public function hasTypingLapsed(): bool
    {
        return $this->typingTo !== null && $this->typingUntil < microtime(true);
    }

    /**
     * Queue a message for the page. Never waits: the queue is written in
     * the background, so one slow page can't hold up the others
     *
     * @param array<string, mixed> $message Sent as JSON
     */
    public function send(array $message): void
    {
        if ($this->client->isClosed()) {
            return;
        }

        $text = json_encode($message, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        $this->queued[] = $text;
        $this->queuedBytes += strlen($text);

        if ($this->queuedBytes > static::MAX_QUEUED_BYTES) {
            $this->queued = [];
            $this->queuedBytes = 0;
            $this->close(static::CLOSE_TOO_SLOW, 'Too slow');
            return;
        }

        if (!$this->isWriting) {
            $this->isWriting = true;
            async($this->writeQueued(...));
        }
    }

    /**
     * Write the queued messages to the page, in order, until none are left
     */
    private function writeQueued(): void
    {
        try {
            while ($this->queued && !$this->client->isClosed()) {
                $text = array_shift($this->queued);
                $this->queuedBytes -= strlen($text);
                $this->client->sendText($text);
            }
        } catch (WebsocketClosedException $e) {
            // The page went; the Hub hears of it from the closed connection
        } finally {
            $this->isWriting = false;
        }
    }

    /**
     * Close the connection, unless it's closed already
     *
     * @param int $code A WebSocket close code, or one of CLOSE_*
     */
    public function close(int $code, string $reason): void
    {
        if (!$this->client->isClosed()) {
            async(fn () => $this->client->close($code, $reason));
        }
    }
}

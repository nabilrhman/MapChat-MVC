<?php

namespace App\ChatSocket;

use Amp\Websocket\WebsocketClient;
use Amp\Websocket\WebsocketClosedException;
use function Amp\async;

/**
 * One open chat page's WebSocket, and where that page is in the chat
 */
class Tab
{
    /**
     * A page that lets more than this pile up unread is too slow: it's cut,
     * and catches up from where it got to when it reconnects
     */
    const MAX_QUEUED_BYTES = 1048576;

    /** Close codes the page acts on (public/js/messages.js) */
    const CLOSE_LOG_IN_AGAIN = 4401;
    const CLOSE_TOO_SLOW = 4408;

    public WebsocketClient $client;

    /** @var \App\Models\User */
    public $user;

    public string $sessionId;

    /** Whether the page said hello, and so takes part */
    public bool $hasSaidHello = false;

    /**
     * Whether it has every message up to the Hub's reading point, so new
     * ones go straight to it. Until then it's catching up
     */
    public bool $isLive = false;

    /** The newest message id the page has been given, or asked to start after */
    public int $cursor = 0;

    /** Who the page says its person is typing to, until when that holds, and when it was last passed on */
    public ?int $typingTo = null;
    public float $typingUntil = 0;
    public float $typingForwardedAt = 0;

    /** Messages waiting to be written, in order, and their size */
    private array $queued = [];
    public int $queuedBytes = 0;
    private bool $isWriting = false;

    /**
     * @param WebsocketClient $client
     * @param \App\Models\User $user
     * @param string $sessionId
     */
    public function __construct($client, $user, $sessionId)
    {
        $this->client = $client;
        $this->user = $user;
        $this->sessionId = $sessionId;
    }

    public function getId(): int
    {
        return $this->client->getId();
    }

    /**
     * Queue a message for the page. Never waits: the queue is written in
     * the background, so one slow page can't hold up the others
     *
     * @param array $message
     *
     * @return void
     */
    public function send($message)
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
     * @return void
     */
    private function writeQueued()
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
     * @param int $code
     * @param string $reason
     *
     * @return void
     */
    public function close($code, $reason)
    {
        if (!$this->client->isClosed()) {
            async(fn () => $this->client->close($code, $reason));
        }
    }
}

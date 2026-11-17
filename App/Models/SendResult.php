<?php

namespace App\Models;

/**
 * How sending a message went (Chat::sendFrom): saved, with its id, or
 * refused, with an HTTP status and what to show the sender
 */
readonly class SendResult
{
    /**
     * Hold the outcome
     *
     * @param int|null $id The saved message's id; null when refused
     * @param int $status HTTP status
     * @param string|null $error What to show the sender; null when saved
     */
    private function __construct(
        public ?int $id,
        public int $status,
        public ?string $error,
    ) {
    }

    /**
     * The message was saved, now or by an earlier try
     */
    public static function sent(int $id): self
    {
        return new self($id, 200, null);
    }

    /**
     * The message wasn't saved, and why, said for the sender
     *
     * @param int $status HTTP status
     */
    public static function refused(int $status, string $error): self
    {
        return new self(null, $status, $error);
    }

    /**
     * Whether the message was saved
     */
    public function isSent(): bool
    {
        return $this->id !== null;
    }
}

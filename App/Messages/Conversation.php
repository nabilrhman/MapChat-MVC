<?php

namespace App\Messages;

/**
 * Which chat a message or a page's tab belongs to: the open room, or a
 * private chat, known by the other person's id. Pages name them "everyone"
 * or that id
 */
readonly class Conversation
{
    /** What pages call the open room */
    const ROOM_PAGE_NAME = 'everyone';

    /**
     * Hold which chat it is
     *
     * @param int|null $partnerId The other person in a private chat; null for the open room
     */
    private function __construct(
        public ?int $partnerId,
    ) {
    }

    /**
     * The open room
     */
    public static function room(): self
    {
        return new self(null);
    }

    /**
     * The private chat with this person
     */
    public static function with(int $partnerId): self
    {
        return new self($partnerId);
    }

    /**
     * The chat a page named
     *
     * @param string $pageName "everyone", or the other person's id
     *
     * @return self|null Null when it's neither
     */
    public static function fromPageName(string $pageName): ?self
    {
        if ($pageName === static::ROOM_PAGE_NAME) {
            return static::room();
        }
        if (ctype_digit($pageName)) {
            return static::with((int) $pageName);
        }

        return null;
    }

    /**
     * The private chat a page named, for what only a private chat has, like
     * read marks
     *
     * @param mixed $pageName The other person's id, as the page sent it
     *
     * @return self|null Null for the open room or anything that isn't a chat
     */
    public static function privateFromPageName(mixed $pageName): ?self
    {
        $conversation = is_string($pageName) ? static::fromPageName($pageName) : null;

        return $conversation?->isRoom() ? null : $conversation;
    }

    /**
     * Whether it's the open room
     */
    public function isRoom(): bool
    {
        return $this->partnerId === null;
    }

    /**
     * What pages call it: "everyone", or the other person's id
     */
    public function pageName(): string
    {
        return $this->isRoom() ? static::ROOM_PAGE_NAME : (string) $this->partnerId;
    }
}

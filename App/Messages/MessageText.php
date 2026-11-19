<?php

namespace App\Messages;

/**
 * What a message says, and what its sender typed when the message box
 * translated it. The two travel together, from the page to the database
 */
readonly class MessageText
{
    /**
     * Hold the message's words
     *
     * @param string|null $original What was typed, when $body is its translation
     */
    public function __construct(
        public string $body,
        public ?string $original = null,
    ) {
    }

    /**
     * The same words without spaces at either end. An original that says
     * the same as the message is no translation, so it's dropped
     */
    public function trimmed(): self
    {
        $body = trim($this->body);
        $original = trim($this->original ?? '');
        $isTranslation = $original !== '' && $original !== $body;

        return new self($body, $isTranslation ? $original : null);
    }

    /**
     * Whether the message, or what was typed for it, is longer than this
     *
     * @param int $maxLength In characters
     */
    public function isLongerThan(int $maxLength): bool
    {
        return mb_strlen($this->body) > $maxLength || mb_strlen($this->original ?? '') > $maxLength;
    }
}

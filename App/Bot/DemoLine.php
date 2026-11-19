<?php

namespace App\Bot;

/**
 * One line of the demo people's stories (database/demo-chat.php): who says
 * it, what they say, and what they typed when they translated it
 */
readonly class DemoLine
{
    /**
     * Hold the line
     *
     * @param string $speaker Their email before @demo.mapchat.local
     * @param string|null $original What they typed, when $body is its translation
     */
    public function __construct(
        public string $speaker,
        public string $body,
        public ?string $original = null,
    ) {
    }

    /**
     * A line as the stories file writes it, short: [speaker, body], or
     * [speaker, body, what was typed] for a translated one
     *
     * @param array{0: string, 1: string, 2?: string} $row
     */
    public static function fromRow(array $row): self
    {
        return new self($row[0], $row[1], $row[2] ?? null);
    }
}

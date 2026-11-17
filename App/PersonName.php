<?php

namespace App;

/**
 * What someone is called in the chat: their name, the nickname they chose,
 * and whether they're a guest, which together give their shown name
 */
readonly class PersonName
{
    /**
     * Hold someone's names
     *
     * @param string|null $nickname Null when they haven't chosen one
     */
    public function __construct(
        public string $name,
        public ?string $nickname = null,
        public bool $isGuest = false,
    ) {
    }

    /**
     * The names in a row with name, nickname and is_guest
     *
     * @param array{name: string, nickname: ?string, is_guest: bool} $row With is_guest cast where it was fetched
     */
    public static function fromRow(array $row): self
    {
        return new self($row['name'], $row['nickname'], $row['is_guest']);
    }

    /**
     * How they're shown in the chat and what's typed after the @ for them:
     * their nickname, or else their first name. Guests are always "Guest"
     * and their number; they can't have a nickname
     */
    public function shown(): string
    {
        if ($this->isGuest) {
            return $this->name;
        }

        return $this->nickname ?: explode(' ', $this->name)[0];
    }
}

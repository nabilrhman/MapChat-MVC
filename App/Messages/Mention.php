<?php

namespace App\Messages;

use App\Models\User;
use App\PersonName;

/**
 * @mentions in the open room: "@" and someone's shown name, shown as a
 * chip. Their whole name counts too
 */
class Mention
{
    /**
     * How someone is shown in the chat (PersonName::shown), for the templates
     *
     * @param string|null $name Null for nobody, like the recipient of a room message, which shows as ''
     */
    public static function shownName(?string $name, ?string $nickname = null, bool $isGuest = false): string
    {
        if ($name === null) {
            return '';
        }

        return (new PersonName($name, $nickname, $isGuest))->shown();
    }

    /**
     * Whether the text mentions this person, by name or shown name
     */
    public static function isMentionedIn(string $text, PersonName $person): bool
    {
        return preg_match(static::pattern([$person->name, $person->shown()]), $text) === 1;
    }

    /**
     * Every way people are mentioned in any of these messages, names and
     * shown names, so a page of them takes one look-up. A translated
     * message's original counts too, as "See original" shows its chips
     *
     * @param array<array{body: string, original_body?: string|null}> $chats
     *
     * @return string[]
     */
    public static function namesIn(array $chats): array
    {
        $texts = [...array_column($chats, 'body'), ...array_filter(array_column($chats, 'original_body'))];
        $bodies = array_filter($texts, fn (string $text): bool => str_contains($text, '@'));
        if (!$bodies) {
            return [];
        }

        $names = [];
        foreach (User::mentionedIn(implode("\n", $bodies)) as $person) {
            $names[] = $person->name;
            $names[] = $person->shown();
        }

        return array_values(array_unique($names));
    }

    /**
     * The message as HTML, with each mention in a chip. The reader's own
     * mentions get a chip of their own colour
     *
     * @param string[] $names From namesIn()
     */
    public static function htmlWithChips(string $text, array $names, PersonName $reader): string
    {
        $html = htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
        if (!$names) {
            return $html;
        }

        // The text is escaped by now, so look for the names escaped the same way
        $escape = fn (string $name): string => htmlspecialchars($name, ENT_QUOTES, 'UTF-8');
        $readerNames = array_map(
            fn (string $name): string => mb_strtolower($escape($name)),
            [$reader->name, $reader->shown()]
        );

        return preg_replace_callback(
            static::pattern(array_map($escape, $names)),
            function (array $match) use ($readerNames): string {
                $class = in_array(mb_strtolower($match[1]), $readerNames, true) ? 'mention me' : 'mention';
                return '<span class="' . $class . '">' . $match[0] . '</span>';
            },
            $html
        );
    }

    /**
     * Add each person's shown name as shown_name, for the scripts
     *
     * @param array<array{name: string, nickname: ?string, is_guest: bool}> $people With is_guest cast where they were fetched
     *
     * @return array[]
     */
    public static function withShownNames(array $people): array
    {
        foreach ($people as &$person) {
            $person['shown_name'] = PersonName::fromRow($person)->shown();
        }

        return $people;
    }

    /**
     * A pattern for "@" and any of the names, whole, in any case. Longer
     * names go first, so "@Maya Thompson" isn't taken for "@Maya"
     *
     * @param string[] $names
     */
    protected static function pattern(array $names): string
    {
        usort($names, fn (string $a, string $b): int => mb_strlen($b) - mb_strlen($a));
        $alternatives = implode('|', array_map(fn (string $name): string => preg_quote($name, '/'), $names));

        return '/@(' . $alternatives . ')(?![\p{L}\p{N}])/iu';
    }
}

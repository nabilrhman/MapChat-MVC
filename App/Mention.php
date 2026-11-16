<?php

namespace App;

use App\Models\User;

/**
 * @mentions in the open room: "@" and someone's shown name, shown as a
 * chip. Their whole name counts too
 */
class Mention
{
    /**
     * How someone is shown in the chat and what's typed after the @ for
     * them: their nickname, or else their first name. Guests are always
     * "Guest" and their number; they can't have a nickname
     *
     * @param string|null $name Null for nobody, like the recipient of a room message, which shows as ''
     */
    public static function shownName(?string $name, ?string $nickname = null, ?bool $isGuest = false): string
    {
        if ($name === null) {
            return '';
        }
        if ($isGuest) {
            return $name;
        }

        return $nickname ?: explode(' ', $name)[0];
    }

    /**
     * Whether the text mentions this person, by name or shown name
     */
    public static function isMentionedIn(string $text, string $name, ?string $nickname = null, bool $isGuest = false): bool
    {
        return preg_match(static::pattern([$name, static::shownName($name, $nickname, $isGuest)]), $text) === 1;
    }

    /**
     * Every way people are mentioned in any of these messages, names and
     * shown names, so a page of them takes one look-up
     *
     * @param array<array{body: string}> $chats
     *
     * @return string[]
     */
    public static function namesIn(array $chats): array
    {
        $bodies = array_filter(array_column($chats, 'body'), fn ($body) => str_contains($body, '@'));
        if (!$bodies) {
            return [];
        }

        $names = [];
        foreach (User::mentionedIn(implode("\n", $bodies)) as $person) {
            $names[] = $person['name'];
            $names[] = static::shownName($person['name'], $person['nickname'], (bool) $person['is_guest']);
        }

        return array_values(array_unique($names));
    }

    /**
     * The message as HTML, with each mention in a chip. The reader's own
     * mentions get a chip of their own colour
     *
     * @param string[] $names From namesIn()
     */
    public static function mark(string $text, array $names, string $readerName, ?string $readerNickname = null, bool $readerIsGuest = false): string
    {
        $html = htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
        if (!$names) {
            return $html;
        }

        // The text is escaped by now, so look for the names escaped the same way
        $escape = fn ($name) => htmlspecialchars($name, ENT_QUOTES, 'UTF-8');
        $readerNames = array_map(
            fn ($name) => mb_strtolower($escape($name)),
            [$readerName, static::shownName($readerName, $readerNickname, $readerIsGuest)]
        );

        return preg_replace_callback(
            static::pattern(array_map($escape, $names)),
            function ($match) use ($readerNames) {
                $class = in_array(mb_strtolower($match[1]), $readerNames, true) ? 'mention me' : 'mention';
                return '<span class="' . $class . '">' . $match[0] . '</span>';
            },
            $html
        );
    }

    /**
     * Add each person's shown name as shown_name, for the scripts
     *
     * @param array<array{name: string, nickname: ?string, is_guest: mixed}> $people
     *
     * @return array[]
     */
    public static function withShownNames(array $people): array
    {
        foreach ($people as &$person) {
            $person['shown_name'] = static::shownName($person['name'], $person['nickname'], (bool) $person['is_guest']);
        }

        return $people;
    }

    /**
     * A pattern for "@" and any of the names, whole, in any case. Longer
     * names go first, so "@Maya Thompson" isn't taken for "@Maya"
     * @param string[] $names
     */
    protected static function pattern(array $names): string
    {
        usort($names, fn ($a, $b) => mb_strlen($b) - mb_strlen($a));
        $alternatives = implode('|', array_map(fn ($name) => preg_quote($name, '/'), $names));

        return '/@(' . $alternatives . ')(?![\p{L}\p{N}])/iu';
    }
}

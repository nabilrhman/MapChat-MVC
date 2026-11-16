<?php

namespace App;

use App\Models\User;

/**
 * @mentions in the open room: "@" and someone's first name, shown as a chip.
 * Their whole name counts too. Guests are all "Guest" and a number, so
 * theirs is the whole name
 */
class Mention
{
    /**
     * What to type after the @ for this person
     *
     * @param string $name
     * @param bool $isGuest
     *
     * @return string
     */
    public static function handle($name, $isGuest = false)
    {
        return $isGuest ? $name : explode(' ', $name)[0];
    }

    /**
     * Whether the text mentions this person
     *
     * @param string $text
     * @param string $name
     * @param bool $isGuest
     *
     * @return bool
     */
    public static function has($text, $name, $isGuest = false)
    {
        return preg_match(static::pattern([$name, static::handle($name, $isGuest)]), $text) === 1;
    }

    /**
     * Every way people are mentioned in any of these messages, names and
     * first names, so a page of them takes one look-up
     *
     * @param array $chats Each with a body
     *
     * @return array
     */
    public static function namesIn($chats)
    {
        $bodies = array_filter(array_column($chats, 'body'), fn ($body) => str_contains($body, '@'));
        if (!$bodies) {
            return [];
        }

        $names = [];
        foreach (User::mentionedIn(implode("\n", $bodies)) as $person) {
            $names[] = $person['name'];
            $names[] = static::handle($person['name'], $person['is_guest']);
        }

        return array_values(array_unique($names));
    }

    /**
     * The message as HTML, with each mention in a chip. The reader's own
     * mentions get a chip of their own colour
     *
     * @param string $text
     * @param array $names From namesIn()
     * @param string $readerName
     * @param bool $readerIsGuest
     *
     * @return string
     */
    public static function mark($text, $names, $readerName, $readerIsGuest = false)
    {
        $html = htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
        if (!$names) {
            return $html;
        }

        // The text is escaped by now, so look for the names escaped the same way
        $escape = fn ($name) => mb_strtolower(htmlspecialchars($name, ENT_QUOTES, 'UTF-8'));
        $readerNames = [$escape($readerName), $escape(static::handle($readerName, $readerIsGuest))];

        return preg_replace_callback(
            static::pattern(array_map(fn ($name) => htmlspecialchars($name, ENT_QUOTES, 'UTF-8'), $names)),
            function ($match) use ($readerNames) {
                $class = in_array(mb_strtolower($match[1]), $readerNames, true) ? 'mention mention--me' : 'mention';
                return '<span class="' . $class . '">' . $match[0] . '</span>';
            },
            $html
        );
    }

    /**
     * "@" and any of the names, whole, in any case. Longer names go first,
     * so "@Maya Thompson" isn't taken for "@Maya"
     *
     * @param array $names
     *
     * @return string
     */
    protected static function pattern($names)
    {
        usort($names, fn ($a, $b) => mb_strlen($b) - mb_strlen($a));
        $alternatives = implode('|', array_map(fn ($name) => preg_quote($name, '/'), $names));

        return '/@(' . $alternatives . ')(?![\p{L}\p{N}])/iu';
    }
}

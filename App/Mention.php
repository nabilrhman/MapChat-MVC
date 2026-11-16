<?php

namespace App;

use App\Models\User;

/**
 * @mentions in the open room: "@" and someone's name, shown as a chip
 */
class Mention
{
    /**
     * Whether the text mentions this name
     *
     * @param string $text
     * @param string $name
     *
     * @return bool
     */
    public static function has($text, $name)
    {
        return preg_match(static::pattern([$name]), $text) === 1;
    }

    /**
     * The names of people mentioned in any of these messages, so a page of
     * them takes one look-up
     *
     * @param array $chats Each with a body
     *
     * @return array
     */
    public static function namesIn($chats)
    {
        $bodies = array_filter(array_column($chats, 'body'), fn ($body) => str_contains($body, '@'));

        return $bodies ? User::namesMentionedIn(implode("\n", $bodies)) : [];
    }

    /**
     * The message as HTML, with each mention of these names in a chip. The
     * reader's own name gets a chip of its own colour
     *
     * @param string $text
     * @param array $names
     * @param string $readerName
     *
     * @return string
     */
    public static function mark($text, $names, $readerName)
    {
        $html = htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
        if (!$names) {
            return $html;
        }

        // The text is escaped by now, so look for the names escaped the same way
        $escapedNames = array_map(fn ($name) => htmlspecialchars($name, ENT_QUOTES, 'UTF-8'), $names);
        $escapedReader = mb_strtolower(htmlspecialchars($readerName, ENT_QUOTES, 'UTF-8'));

        return preg_replace_callback(static::pattern($escapedNames), function ($match) use ($escapedReader) {
            $class = mb_strtolower($match[1]) === $escapedReader ? 'mention mention--me' : 'mention';
            return '<span class="' . $class . '">' . $match[0] . '</span>';
        }, $html);
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

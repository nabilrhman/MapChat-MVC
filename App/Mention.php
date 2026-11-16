<?php

namespace App;

use App\Models\User;

/**
 * @mentions in the open room: "@" and someone's handle, shown as a chip.
 * Their whole name counts too
 */
class Mention
{
    /**
     * How someone is shown in the chat and what's typed after the @ for
     * them: their nickname, or else their first name. Guests are all
     * "Guest" and a number, so theirs is the whole name
     *
     * @param string $name
     * @param string|null $nickname
     * @param bool $isGuest
     *
     * @return string
     */
    public static function handle($name, $nickname = null, $isGuest = false)
    {
        if ($nickname) {
            return $nickname;
        }

        return $isGuest ? $name : explode(' ', $name)[0];
    }

    /**
     * Whether the text mentions this person
     *
     * @param string $text
     * @param string $name
     * @param string|null $nickname
     * @param bool $isGuest
     *
     * @return bool
     */
    public static function has($text, $name, $nickname = null, $isGuest = false)
    {
        return preg_match(static::pattern([$name, static::handle($name, $nickname, $isGuest)]), $text) === 1;
    }

    /**
     * Every way people are mentioned in any of these messages, names and
     * handles, so a page of them takes one look-up
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
            $names[] = static::handle($person['name'], $person['nickname'], $person['is_guest']);
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
     * @param string|null $readerNickname
     * @param bool $readerIsGuest
     *
     * @return string
     */
    public static function mark($text, $names, $readerName, $readerNickname = null, $readerIsGuest = false)
    {
        $html = htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
        if (!$names) {
            return $html;
        }

        // The text is escaped by now, so look for the names escaped the same way
        $escape = fn ($name) => htmlspecialchars($name, ENT_QUOTES, 'UTF-8');
        $readerNames = array_map(
            fn ($name) => mb_strtolower($escape($name)),
            [$readerName, static::handle($readerName, $readerNickname, $readerIsGuest)]
        );

        return preg_replace_callback(
            static::pattern(array_map($escape, $names)),
            function ($match) use ($readerNames) {
                $class = in_array(mb_strtolower($match[1]), $readerNames, true) ? 'mention mention--me' : 'mention';
                return '<span class="' . $class . '">' . $match[0] . '</span>';
            },
            $html
        );
    }

    /**
     * Add each person's handle as shown_name, for the scripts
     *
     * @param array $people Rows with name, nickname and is_guest
     *
     * @return array
     */
    public static function withShownNames($people)
    {
        foreach ($people as &$person) {
            $person['shown_name'] = static::handle($person['name'], $person['nickname'], $person['is_guest']);
        }

        return $people;
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

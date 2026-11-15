<?php

namespace App;

/**
 * Picture for people who haven't uploaded an avatar: their initials on a
 * coloured gradient, the same style as the sample people's avatars. Each
 * person always gets the same colour.
 */
class Avatar
{
    /** Pairs of gradient colours to choose from */
    const COLOURS = [
        ['#f06292', '#ba68c8'],
        ['#4db6ac', '#26a69a'],
        ['#ffb74d', '#ff8a65'],
        ['#7986cb', '#5c6bc0'],
        ['#81c784', '#4caf50'],
        ['#64b5f6', '#42a5f5'],
        ['#e57373', '#ec407a'],
        ['#9575cd', '#7e57c2'],
        ['#4dd0e1', '#26c6da'],
    ];

    /**
     * Up to two initials: the first letter of the first two words that start
     * with a letter, so "Guest 4218" becomes "G" rather than "G4"
     *
     * @param string $name
     *
     * @return string
     */
    public static function initials($name)
    {
        $words = preg_split('/\s+/u', trim((string) $name), -1, PREG_SPLIT_NO_EMPTY);
        $words = array_values(array_filter($words, fn ($word) => preg_match('/^\p{L}/u', $word)));
        $initials = '';

        foreach (array_slice($words, 0, 2) as $word) {
            $initials .= mb_strtoupper(mb_substr($word, 0, 1));
        }

        return $initials === '' ? '?' : $initials;
    }

    /**
     * The avatar as an SVG image
     *
     * @param string $name Shown as initials
     * @param int $seed Picks the colour, e.g. the user's id
     *
     * @return string
     */
    public static function svg($name, $seed)
    {
        [$from, $to] = static::COLOURS[abs((int) $seed) % count(static::COLOURS)];
        $initials = htmlspecialchars(static::initials($name), ENT_XML1 | ENT_QUOTES, 'UTF-8');

        return <<<SVG
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 96 96" role="img" aria-label="{$initials}">
<defs><linearGradient id="g" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="{$from}"/><stop offset="1" stop-color="{$to}"/></linearGradient></defs>
<rect width="96" height="96" fill="url(#g)"/>
<text x="48" y="49" text-anchor="middle" dominant-baseline="central" fill="#fff" font-family="Montserrat, 'Helvetica Neue', Arial, sans-serif" font-size="36" font-weight="600" letter-spacing="1">{$initials}</text>
</svg>
SVG;
    }
}

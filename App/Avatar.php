<?php

namespace App;

/**
 * Picture for people who haven't uploaded an avatar: their initials on a
 * coloured gradient, the same style as the sample people's avatars, or for
 * the demo people a drawn face. Each person always gets the same one.
 */
class Avatar
{
    /** Line colour of the demo people's faces */
    const INK = '#1f1f1f';

    /** Pastel backgrounds for the demo people's faces */
    const PASTELS = ['#fde2e4', '#e2ece9', '#dfe7fd', '#fff1e6', '#e9f5db', '#fad2e1', '#d7e3fc', '#fcefb4', '#e4dcf1', '#d8f3dc'];

    /** Face parts, drawn on a 96 x 96 grid in the ink colour */
    const HAIR = [
        'crop' => '<path d="M27 46C25 27 37 18 49 18C62 18 72 28 69 46C66 37 58 31 48 31C38 31 31 37 27 46Z"/>',
        'swoop' => '<path d="M27 47C23 26 42 13 60 19C71 23 73 35 69 47C66 38 61 32 54 30C50 36 40 38 30 37C28 40 27 43 27 47Z"/>',
        'long' => '<path d="M48 16C33 16 24 28 25 45C25 58 22 68 17 76C27 79 36 74 33 64L33 48C40 46 48 38 50 30C55 38 60 44 63 48L63 64C60 74 69 79 79 76C74 68 71 58 71 45C72 28 63 16 48 16Z"/>',
        'bun' => '<circle cx="48" cy="15" r="8"/><path d="M27 45C26 29 36 21 48 21C60 21 70 29 69 45C64 36 57 32 48 32C39 32 32 36 27 45Z"/>',
        'curly' => '<circle cx="33" cy="32" r="7"/><circle cx="41" cy="24" r="8"/><circle cx="52" cy="22" r="8"/><circle cx="62" cy="27" r="7"/><circle cx="67" cy="36" r="6"/><circle cx="28" cy="40" r="5"/>',
        'beanie' => '<path d="M26 40C26 25 36 17 48 17C60 17 70 25 70 40Z"/><rect x="24" y="36" width="48" height="7" rx="3.5"/>',
    ];

    const EYES = [
        'dots' => '<circle cx="40" cy="48" r="2.4"/><circle cx="56" cy="48" r="2.4"/>',
        'happy' => '<path d="M36.5 48.5Q40 45 43.5 48.5M52.5 48.5Q56 45 59.5 48.5" fill="none" stroke="#1f1f1f" stroke-width="2.4" stroke-linecap="round"/>',
        'oval' => '<ellipse cx="40" cy="48" rx="2" ry="3"/><ellipse cx="56" cy="48" rx="2" ry="3"/>',
    ];

    const BROWS = [
        'none' => '',
        'brows' => '<path d="M36 41.5L43 40.5M53 40.5L60 41.5" fill="none" stroke="#1f1f1f" stroke-width="2.4" stroke-linecap="round"/>',
    ];

    const MOUTHS = [
        'smile' => '<path d="M42 59Q48 64 54 59" fill="none" stroke="#1f1f1f" stroke-width="2.4" stroke-linecap="round"/>',
        'open' => '<path d="M41 57.5Q48 66 55 57.5Z"/>',
        'flat' => '<path d="M43.5 60H52.5" stroke="#1f1f1f" stroke-width="2.4" stroke-linecap="round"/>',
        'smirk' => '<path d="M43 60Q49 63 54 58" fill="none" stroke="#1f1f1f" stroke-width="2.4" stroke-linecap="round"/>',
        'o' => '<circle cx="48" cy="60" r="2.2"/>',
    ];

    const EXTRAS = [
        'none' => '',
        'none2' => '',
        'none3' => '',
        'glasses' => '<g fill="none" stroke="#1f1f1f" stroke-width="2.2"><circle cx="40" cy="48" r="6"/><circle cx="56" cy="48" r="6"/><path d="M46 48H50M34 47L29 45M62 47L67 45"/></g>',
        'freckles' => '<g opacity="0.55"><circle cx="35" cy="55" r="0.9"/><circle cx="38" cy="56.5" r="0.9"/><circle cx="58" cy="56.5" r="0.9"/><circle cx="61" cy="55" r="0.9"/></g>',
        // A hole around the mouth, so the mouth drawn after it still shows
        'beard' => '<path fill-rule="evenodd" d="M28.5 50C29 63 37 70.5 48 70.5C59 70.5 67 63 67.5 50C65 56 61 57.5 57 57C54 54.5 51 55 48 55.5C45 55 42 54.5 39 57C35 57.5 31 56 28.5 50ZM48 57.5C43 57.5 40.5 59 40.5 60.5C40.5 63 44 65 48 65C52 65 55.5 63 55.5 60.5C55.5 59 53 57.5 48 57.5Z"/>',
        'moustache' => '<path d="M40.5 58C43 55 46 55 48 56.5C50 55 53 55 55.5 58C53 59 50.5 58.6 48 57.8C45.5 58.6 43 59 40.5 58Z"/>',
    ];

    /** The demo people's faces, picked to suit each of them */
    const LOOKS = [
        'Aiko Tanaka' => ['hair' => 'bun', 'eyes' => 'happy', 'mouth' => 'smile', 'brows' => 'none', 'extra' => 'none', 'bg' => '#fde2e4'],
        'Maya Thompson' => ['hair' => 'long', 'eyes' => 'dots', 'mouth' => 'open', 'brows' => 'none', 'extra' => 'glasses', 'bg' => '#dfe7fd'],
        'Wanjiru Kamau' => ['hair' => 'curly', 'eyes' => 'dots', 'mouth' => 'open', 'brows' => 'none', 'extra' => 'none', 'bg' => '#fcefb4'],
        'Sofia Ramírez' => ['hair' => 'long', 'eyes' => 'oval', 'mouth' => 'smile', 'brows' => 'brows', 'extra' => 'freckles', 'bg' => '#fad2e1'],
        'Emily Carter' => ['hair' => 'long', 'eyes' => 'happy', 'mouth' => 'smirk', 'brows' => 'none', 'extra' => 'none', 'bg' => '#e9f5db'],
        'Rafael Costa' => ['hair' => 'crop', 'eyes' => 'dots', 'mouth' => 'smile', 'brows' => 'brows', 'extra' => 'beard', 'bg' => '#e2ece9'],
        'Tanvir Hasan' => ['hair' => 'swoop', 'eyes' => 'oval', 'mouth' => 'smirk', 'brows' => 'brows', 'extra' => 'moustache', 'bg' => '#fff1e6'],
        'Lukas Becker' => ['hair' => 'beanie', 'eyes' => 'dots', 'mouth' => 'flat', 'brows' => 'none', 'extra' => 'glasses', 'bg' => '#d7e3fc'],
        'Jae-won Park' => ['hair' => 'crop', 'eyes' => 'happy', 'mouth' => 'open', 'brows' => 'brows', 'extra' => 'none', 'bg' => '#e4dcf1'],
    ];

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
     * Image address for an avatar: the uploaded photo, or the letter avatar
     * inline, so a page full of people needs no request per avatar
     *
     * @param int $id
     * @param string $name
     * @param string|null $photo The uploaded photo's URL, if any
     * @param bool $is_demo The demo people (database/demo.sql) get a drawn face
     *
     * @return string
     */
    public static function src($id, $name, $photo = null, $is_demo = false)
    {
        if ($photo && !$is_demo) {
            return $photo;
        }

        $svg = $is_demo ? static::face($id, $name) : static::svg($name, $id);

        return 'data:image/svg+xml;base64,' . base64_encode($svg);
    }

    /**
     * A demo person's avatar: a line-drawn face on a pastel background, put
     * together from the parts above. The same person always gets the same face
     *
     * @param int $seed The person's id
     * @param string|null $name A name in LOOKS gets that look; anyone else a mix
     *
     * @return string SVG
     */
    public static function face($seed, $name = null)
    {
        $look = static::LOOKS[$name] ?? null;
        // A separate hash per part, so neighbouring ids look nothing alike
        $pick = fn ($parts, $part) => $look
            ? ($part === 'bg' ? $look['bg'] : $parts[$look[$part]])
            : array_values($parts)[crc32($part . $seed) % count($parts)];

        $hair = $pick(static::HAIR, 'hair');
        // Long hair falls behind the shoulders, so it's drawn first
        $is_long = $hair === static::HAIR['long'];
        $line = 'stroke="' . static::INK . '" stroke-width="2.6"';

        // Framed close on the head, so the face still reads at list size
        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="11 5 74 74">'
            . '<rect x="11" y="5" width="74" height="74" fill="' . $pick(static::PASTELS, 'bg') . '"/>'
            . '<g fill="' . static::INK . '">' . ($is_long ? $hair : '') . '</g>'
            . '<path d="M14 96C16 82 28 76 40 74L56 74C68 76 80 82 82 96Z" fill="#fff" ' . $line . '/>'
            . '<path d="M42 64V75Q48 80 54 75V64" fill="#fff" ' . $line . '/>'
            . '<ellipse cx="29" cy="50" rx="3.5" ry="5" fill="#fff" ' . $line . '/>'
            . '<ellipse cx="67" cy="50" rx="3.5" ry="5" fill="#fff" ' . $line . '/>'
            . '<ellipse cx="48" cy="47" rx="20" ry="22" fill="#fff" ' . $line . '/>'
            . '<g fill="' . static::INK . '">'
            . ($is_long ? '' : $hair) . $pick(static::EYES, 'eyes') . $pick(static::BROWS, 'brows')
            . $pick(static::EXTRAS, 'extra') . $pick(static::MOUTHS, 'mouth')
            . '</g>'
            . '<path d="M48 50Q45.5 55 49 55.5" fill="none" stroke="' . static::INK . '" stroke-width="2" stroke-linecap="round"/>'
            . '</svg>';
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

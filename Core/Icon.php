<?php

namespace Core;

/**
 * Icons from the sprite in public/icons.svg, drawn in the text colour at
 * the font size (see .icon in styles.css)
 */
class Icon
{
    /**
     * An icon's <svg>, e.g. svg('sun', 'daylight'). data-icon names it, for
     * the few icons styles.css fits to their drawing
     *
     * @param string $name The icon's id in the sprite
     * @param string $class More classes for it
     */
    public static function svg(string $name, string $class = ''): string
    {
        $href = Asset::url('/icons.svg') . '#' . $name;

        return '<svg class="' . htmlspecialchars(trim("icon $class")) . '" data-icon="' . htmlspecialchars($name) . '" aria-hidden="true">'
            . '<use href="' . htmlspecialchars($href) . '"></use></svg>';
    }
}

<?php

namespace Core;

/**
 * Icons from the sprite in public/icons.svg, drawn in the text colour at
 * the font size (see .icon in base.css)
 */
class Icon
{
    /**
     * An icon's <svg>, e.g. svg('map-pin', 'chat__place-icon')
     *
     * @param string $name  The icon's id in the sprite
     * @param string $class More classes for it
     *
     * @return string
     */
    public static function svg($name, $class = '')
    {
        $href = Asset::url('/icons.svg') . '#' . $name;

        return '<svg class="' . htmlspecialchars(trim("icon icon--$name $class")) . '" aria-hidden="true">'
            . '<use href="' . htmlspecialchars($href) . '"></use></svg>';
    }
}

<?php

namespace Core;

/**
 * Links to the site's own CSS, JavaScript and images that change whenever the
 * file does, so browsers fetch the new version instead of keeping the old one
 * in their cache. No version numbers to remember to bump
 */
class Asset
{
    /**
     * The path with a short hash of the file's contents, e.g. /css/ui.css?v=1a2b3c4d
     *
     * @param string $path The file's path under public/, starting with /
     */
    public static function url(string $path): string
    {
        static $versionedUrls = [];

        if (!isset($versionedUrls[$path])) {
            $file = dirname(__DIR__) . '/public' . $path;
            // A missing file still gets its plain link, so the browser reports the 404
            $versionedUrls[$path] = is_file($file) ? $path . '?v=' . hash_file('crc32b', $file) : $path;
        }

        return $versionedUrls[$path];
    }
}

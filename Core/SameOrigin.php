<?php

namespace Core;

use App\Config;

/**
 * Whether a request comes from a page on this site, for posts (CSRF) and
 * the chat socket. The page's origin, which browsers send and another
 * site's page can't change, must have the scheme, host and port of APP_URL
 * or of the address the request came to
 */
class SameOrigin
{
    /** The port each scheme uses when an address names none */
    const DEFAULT_PORTS = ['http' => 80, 'https' => 443];

    /**
     * Whether the page that sent a request is this site's. No origin at all
     * is not: browsers send one with every post and socket
     *
     * @param string|null $source The Origin header, or else the Referer
     * @param string|null $host The Host header, e.g. localhost:8080
     * @param bool $isHttps Whether the request reached the site over https
     */
    public static function isFromThisSite(?string $source, ?string $host, bool $isHttps): bool
    {
        $sourceOrigin = static::originOf($source ?? '');
        if ($sourceOrigin === null) {
            return false;
        }

        $appUrl = Config::get('APP_URL');
        if ($appUrl !== null && static::originOf($appUrl) === $sourceOrigin) {
            return true;
        }

        if ($host === null || $host === '') {
            return false;
        }

        return static::originOf(($isHttps ? 'https://' : 'http://') . $host) === $sourceOrigin;
    }

    /**
     * A web address's scheme, host and port, with the port filled in, e.g.
     * "https://example.com:443"
     *
     * @return string|null Null for anything that isn't an http or https address, like "null"
     */
    public static function originOf(string $url): ?string
    {
        $parts = parse_url($url);
        if (!is_array($parts)) {
            return null;
        }

        $scheme = strtolower($parts['scheme'] ?? '');
        $host = strtolower($parts['host'] ?? '');
        if (!isset(static::DEFAULT_PORTS[$scheme]) || $host === '') {
            return null;
        }

        $port = $parts['port'] ?? static::DEFAULT_PORTS[$scheme];

        return "$scheme://$host:$port";
    }
}

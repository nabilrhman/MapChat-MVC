<?php

namespace Core;

/**
 * The Content-Security-Policy every page is sent with: a script runs only if
 * it carries this request's nonce, or a nonced script loaded it (the map
 * libraries), so a script slipped into a message or a name never runs
 */
class ContentSecurityPolicy
{
    /** Where each kind of resource may come from, past the site itself */
    const SOURCES = [
        'style-src' => ['https://fonts.googleapis.com', 'https://cdn.jsdelivr.net', 'https://unpkg.com'],
        'font-src' => ['https://fonts.gstatic.com', 'https://cdn.jsdelivr.net', 'data:'],
        'img-src' => [
            'data:', 'blob:', 'https://res.cloudinary.com', 'https://cdn.jsdelivr.net',
            // Photos of people who signed in with Google (App\Google::photoOf)
            'https://*.googleusercontent.com',
            'https://tile.openstreetmap.org', 'https://tiles.openfreemap.org',
        ],
        'connect-src' => ['data:', 'https://api.cloudinary.com', 'https://tiles.openfreemap.org', 'https://unpkg.com'],
        // MapLibre draws in a worker it makes from a blob
        'worker-src' => ['blob:'],
        'child-src' => ['blob:'],
    ];

    private static ?string $nonce = null;

    /**
     * This request's nonce, for the views' script and style tags
     */
    public static function nonce(): string
    {
        return self::$nonce ??= base64_encode(random_bytes(16));
    }

    /**
     * The policy's header value for a request to this host
     *
     * @param string $host The request's Host, for the chat socket's address
     */
    public static function headerFor(string $host): string
    {
        $nonce = "'nonce-" . self::nonce() . "'";
        $socket = ['ws://' . $host, 'wss://' . $host];

        $directives = [
            'default-src' => ["'self'"],
            // strict-dynamic trusts what a nonced script loads; hosts are ignored
            'script-src' => [$nonce, "'strict-dynamic'"],
            'style-src' => ["'self'", $nonce, ...self::SOURCES['style-src']],
            'font-src' => ["'self'", ...self::SOURCES['font-src']],
            'img-src' => ["'self'", ...self::SOURCES['img-src']],
            'connect-src' => ["'self'", ...$socket, ...self::SOURCES['connect-src']],
            'worker-src' => ["'self'", ...self::SOURCES['worker-src']],
            'child-src' => self::SOURCES['child-src'],
            'manifest-src' => ["'self'"],
            'object-src' => ["'none'"],
            'base-uri' => ["'none'"],
            'form-action' => ["'self'"],
            'frame-ancestors' => ["'none'"],
        ];

        $parts = array_map(
            static fn(string $name, array $sources): string => $name . ' ' . implode(' ', $sources),
            array_keys($directives),
            $directives,
        );

        return implode('; ', $parts);
    }

    /**
     * Send the policy with this response
     */
    public static function send(): void
    {
        // Only a host name and port: anything else could add a directive
        $host = preg_replace('/[^A-Za-z0-9.:\[\]-]/', '', $_SERVER['HTTP_HOST'] ?? '') ?: 'localhost';
        header('Content-Security-Policy: ' . self::headerFor($host));
    }
}

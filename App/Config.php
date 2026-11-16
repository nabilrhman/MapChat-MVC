<?php

namespace App;

/**
 * App configuration, read from environment variables
 *
 * Copy .env.example to .env for local development; on a host, set the same
 * variables in its dashboard. Nothing secret lives in the repository
 */
class Config
{
    /**
     * Read a setting from the environment
     *
     * @param string $key Variable name
     * @param mixed $default Used when the variable is unset or empty
     *
     * @return mixed The variable's text, or the default
     */
    public static function get(string $key, mixed $default = null): mixed
    {
        $value = getenv($key);

        return ($value === false || $value === '') ? $default : $value;
    }

    /**
     * Read a true/false setting from the environment
     *
     * @param string $key Variable name
     */
    public static function flag(string $key): bool
    {
        return filter_var(static::get($key, false), FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * Base URL of the site, used in links inside emails, e.g. https://mapchat.example.com
     */
    public static function appUrl(): string
    {
        $url = static::get('APP_URL');

        if ($url === null) {
            $url = (static::isHttpsRequest() ? 'https://' : 'http://') . ($_SERVER['HTTP_HOST'] ?? 'localhost');
        }

        return rtrim($url, '/');
    }

    /**
     * Whether this request reached the site over https, directly or through
     * the host's proxy
     */
    public static function isHttpsRequest(): bool
    {
        return ($_SERVER['HTTPS'] ?? '') === 'on'
            || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
    }

    /**
     * Whether outgoing email is configured. Without it, new accounts are
     * activated straight away so the app still works as a demo
     */
    public static function mailEnabled(): bool
    {
        return static::get('MAILER_DSN') !== null;
    }

    /**
     * Id of the running build (see the Dockerfile), or '' outside Docker
     */
    public static function buildId(): string
    {
        $file = dirname(__DIR__) . '/build-id';

        return is_readable($file) ? trim(file_get_contents($file)) : '';
    }

    /**
     * The local port the chat socket listens on (WebSocket over TCP), and
     * hears that new messages were saved on (UDP). Apache passes /ws to it
     */
    public static function chatSocketPort(): int
    {
        return (int) static::get('CHAT_SOCKET_PORT', 8090);
    }

    /**
     * Cloudinary account that profile photos are uploaded to
     */
    public static function cloudinaryCloudName(): string
    {
        return static::get('CLOUDINARY_CLOUD_NAME', 'nabilr');
    }

    /**
     * Cloudinary's unsigned upload preset, which lets the browser upload directly
     */
    public static function cloudinaryUploadPreset(): string
    {
        return static::get('CLOUDINARY_UPLOAD_PRESET', 'eu0zgzwd');
    }
}

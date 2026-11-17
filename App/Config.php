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
    /** Where links point while APP_URL is unset: the local site docker compose serves */
    const LOCAL_URL = 'http://localhost:8080';

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
     * Whether a true/false setting in the environment is on
     *
     * @param string $key Variable name
     */
    public static function isOn(string $key): bool
    {
        return filter_var(static::get($key, false), FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * Base URL of the site, used in links inside emails, e.g. https://mapchat.example.com.
     * Never the request's Host header: anyone can send one, and a reset link
     * built from it would hand the account's token to their site
     */
    public static function appUrl(): string
    {
        $url = static::get('APP_URL');

        if ($url === null) {
            static::warnOnceAppUrlIsUnset();
            $url = static::LOCAL_URL;
        }

        return rtrim($url, '/');
    }

    /**
     * Log, once per process, that APP_URL is unset, as a real site's email
     * links then point at LOCAL_URL
     */
    protected static function warnOnceAppUrlIsUnset(): void
    {
        static $hasWarned = false;

        if (!$hasWarned) {
            error_log('APP_URL is not set, so links in emails point at ' . static::LOCAL_URL . ': set it to the site\'s address');
            $hasWarned = true;
        }
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
    public static function isMailEnabled(): bool
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
     * Cloudinary account that profile photos are uploaded to, or null when
     * this site has none
     */
    public static function cloudinaryCloudName(): ?string
    {
        return static::get('CLOUDINARY_CLOUD_NAME');
    }

    /**
     * Cloudinary's unsigned upload preset, which lets the browser upload
     * directly, or null when this site has none
     */
    public static function cloudinaryUploadPreset(): ?string
    {
        return static::get('CLOUDINARY_UPLOAD_PRESET');
    }

    /**
     * Whether people can upload a photo. It takes both Cloudinary settings,
     * with no default account, so a copy never uploads to someone else's
     */
    public static function arePhotoUploadsOn(): bool
    {
        return static::cloudinaryCloudName() !== null && static::cloudinaryUploadPreset() !== null;
    }
}

<?php

namespace App;

/**
 * App configuration, read from environment variables.
 *
 * Copy .env.example to .env for local development; on a host, set the same
 * variables in its dashboard. Nothing secret lives in the repository.
 */
class Config
{
    /**
     * Read a setting from the environment
     *
     * @param string $key Variable name
     * @param mixed $default Value used when the variable is unset or empty
     *
     * @return mixed
     */
    public static function get(string $key, $default = null)
    {
        $value = getenv($key);

        return ($value === false || $value === '') ? $default : $value;
    }

    /**
     * Read a true/false setting from the environment
     *
     * @param string $key Variable name
     *
     * @return bool
     */
    public static function flag(string $key): bool
    {
        return filter_var(static::get($key, false), FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * Base URL of the site, used in links inside emails, e.g. https://mapchat.example.com
     *
     * @return string
     */
    public static function appUrl(): string
    {
        $url = static::get('APP_URL');

        if ($url === null) {
            $https = ($_SERVER['HTTPS'] ?? '') === 'on'
                || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
            $url = ($https ? 'https://' : 'http://') . ($_SERVER['HTTP_HOST'] ?? 'localhost');
        }

        return rtrim($url, '/');
    }

    /**
     * Whether outgoing email is configured. Without it, new accounts are
     * activated straight away so the app still works as a demo.
     *
     * @return bool
     */
    public static function mailEnabled(): bool
    {
        return static::get('MAILER_DSN') !== null;
    }

    /**
     * Cloudinary account that profile photos are uploaded to
     *
     * @return string
     */
    public static function cloudinaryCloudName(): string
    {
        return static::get('CLOUDINARY_CLOUD_NAME', 'nabilr');
    }

    /**
     * Cloudinary's unsigned upload preset, which lets the browser upload directly
     *
     * @return string
     */
    public static function cloudinaryUploadPreset(): string
    {
        return static::get('CLOUDINARY_UPLOAD_PRESET', 'eu0zgzwd');
    }
}

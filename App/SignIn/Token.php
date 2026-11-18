<?php

namespace App\SignIn;

use App\Config;

/**
 * A random token for a link or cookie. Only its hash is stored, so a copy
 * of the database can't be used to log in
 */
class Token
{
    /** The token itself, 32 hex characters */
    public readonly string $value;

    /**
     * A new random token, or an existing one to hash
     *
     * @param string|null $value A token from a link or cookie; null for a new one
     */
    public function __construct(?string $value = null)
    {
        // 16 bytes = 128 bits = 32 hex characters
        $this->value = $value ?? bin2hex(random_bytes(16));
    }

    /**
     * The token as stored: an HMAC with SECRET_KEY, 64 hex characters
     */
    public function getHash(): string
    {
        $secret = Config::get('SECRET_KEY');
        if ($secret === null) {
            throw new \RuntimeException('SECRET_KEY is not set');
        }

        return hash_hmac('sha256', $this->value, $secret);
    }
}

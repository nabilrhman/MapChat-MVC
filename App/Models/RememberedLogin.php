<?php

namespace App\Models;

use App\SignIn\Token;
use PDO;

/**
 * A browser kept logged in by "Remember me": a row per cookie, found by
 * the token's hash
 */
class RememberedLogin extends \Core\Model
{
    public string $token_hash;
    public int $user_id;
    /** UTC, Y-m-d H:i:s */
    public string $expires_at;

    /**
     * Remember a login until the given time
     *
     * @param int $expiresAt Unix time
     *
     * @return string The token for the browser's cookie
     */
    public static function create(int $userId, int $expiresAt): string
    {
        $token = new Token();

        $stmt = static::getDB()->prepare('INSERT INTO remembered_logins (token_hash, user_id, expires_at)
                                          VALUES (:token_hash, :user_id, :expires_at)');
        $stmt->bindValue(':token_hash', $token->getHash(), PDO::PARAM_STR);
        $stmt->bindValue(':user_id', $userId, PDO::PARAM_INT);
        $stmt->bindValue(':expires_at', gmdate('Y-m-d H:i:s', $expiresAt), PDO::PARAM_STR);
        $stmt->execute();

        return $token->value;
    }

    /**
     * The remembered login a cookie's token stands for, if any
     *
     * @param string $token The token from the remember-me cookie
     */
    public static function findByToken(string $token): ?static
    {
        if ($token === '') {
            return null;
        }

        $stmt = static::getDB()->prepare('SELECT * FROM remembered_logins WHERE token_hash = :token_hash');
        $stmt->bindValue(':token_hash', (new Token($token))->getHash(), PDO::PARAM_STR);
        $stmt->setFetchMode(PDO::FETCH_CLASS, static::class);
        $stmt->execute();

        return $stmt->fetch() ?: null;
    }

    /**
     * Whether it's past the time it was remembered until
     */
    public function hasExpired(): bool
    {
        return strtotime($this->expires_at . ' UTC') < time();
    }

    /**
     * Forget this login
     */
    public function delete(): void
    {
        $stmt = static::getDB()->prepare('DELETE FROM remembered_logins WHERE token_hash = :token_hash');
        $stmt->bindValue(':token_hash', $this->token_hash, PDO::PARAM_STR);
        $stmt->execute();
    }
}

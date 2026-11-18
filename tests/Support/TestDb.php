<?php

namespace Tests;

use PDO;

/**
 * Gives the tests the app's own database connection, and the rows most
 * tests start from
 */
class TestDb extends \Core\Model
{
    /**
     * The app's own database connection
     */
    public static function get(): PDO
    {
        return static::getDB();
    }

    /**
     * Add an active account, and give back its id
     *
     * @param string|null $password Null for a hash no password matches, for an account nobody logs in to
     */
    public static function createUser(string $name, string $email, ?string $password = null, bool $isGuest = false): int
    {
        $passwordHash = $password === null ? '!' : password_hash($password, PASSWORD_DEFAULT);

        $db = static::getDB();
        $db->prepare('INSERT INTO users (name, email, password_hash, is_active, is_guest) VALUES (?, ?, ?, TRUE, ?)')
            ->execute([$name, $email, $passwordHash, (int) $isGuest]);

        return (int) $db->lastInsertId();
    }
}

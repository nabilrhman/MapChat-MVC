<?php

namespace App\Models;

use PDO;

/**
 * A browser that gets Web Push notifications for someone, one row each.
 * A browser belongs to whoever subscribed it last, so signing in as someone
 * else on it moves it over
 */
class PushSubscription extends \Core\Model
{
    /**
     * Save this browser for this person, or move it to them
     *
     * @param string $endpoint The browser's push service address
     * @param string $p256dh Its public key
     * @param string $auth Its secret
     */
    public static function save(string $email, string $endpoint, string $p256dh, string $auth): void
    {
        $sql = 'INSERT INTO push_subscriptions (email, endpoint, endpoint_hash, p256dh, auth)
                VALUES (:email, :endpoint, :endpoint_hash, :p256dh, :auth)
                ON DUPLICATE KEY UPDATE email = VALUES(email), p256dh = VALUES(p256dh), auth = VALUES(auth)';

        $stmt = static::getDB()->prepare($sql);
        $stmt->bindValue(':email', $email, PDO::PARAM_STR);
        $stmt->bindValue(':endpoint', $endpoint, PDO::PARAM_STR);
        $stmt->bindValue(':endpoint_hash', hash('sha256', $endpoint), PDO::PARAM_STR);
        $stmt->bindValue(':p256dh', $p256dh, PDO::PARAM_STR);
        $stmt->bindValue(':auth', $auth, PDO::PARAM_STR);
        $stmt->execute();
    }

    /**
     * Forget this browser, if it's this person's
     */
    public static function delete(string $email, string $endpoint): void
    {
        $stmt = static::getDB()->prepare('DELETE FROM push_subscriptions WHERE endpoint_hash = :endpoint_hash AND email = :email');
        $stmt->bindValue(':endpoint_hash', hash('sha256', $endpoint), PDO::PARAM_STR);
        $stmt->bindValue(':email', $email, PDO::PARAM_STR);
        $stmt->execute();
    }

    /**
     * Forget a browser its push service no longer knows, whoever it was for
     */
    public static function deleteGone(string $endpoint): void
    {
        $stmt = static::getDB()->prepare('DELETE FROM push_subscriptions WHERE endpoint_hash = :endpoint_hash');
        $stmt->bindValue(':endpoint_hash', hash('sha256', $endpoint), PDO::PARAM_STR);
        $stmt->execute();
    }

    /**
     * Every subscribed browser, with its owner's names for spotting @mentions
     *
     * @return array Each with email, endpoint, p256dh, auth, name, nickname and is_guest
     */
    public static function getAll(): array
    {
        $sql = 'SELECT push_subscriptions.email, endpoint, p256dh, auth, users.name, users.nickname, users.is_guest
                FROM push_subscriptions
                JOIN users
                ON push_subscriptions.email = users.email';

        return static::getDB()->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    }
}

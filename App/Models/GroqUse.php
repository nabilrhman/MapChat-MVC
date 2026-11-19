<?php

namespace App\Models;

use App\Bot\GroqUseKind;
use PDO;

/**
 * Each time a person asked Groq to translate or to turn a recording into
 * text, kept per account, so a fresh session or many requests at once
 * can't get past the limit a minute
 */
class GroqUse extends \Core\Model
{
    /**
     * Whether the person has used up the minute's turns at this
     *
     * @param int $limit Turns a minute
     */
    public static function isUsedUp(int $userId, GroqUseKind $kind, int $limit): bool
    {
        return static::countInLastMinute($userId, $kind) >= $limit;
    }

    /**
     * Take one of the minute's turns at this, if one is left. Counted and
     * taken under one lock, so requests sent at once can't all slip through
     *
     * @param int $limit Turns a minute
     *
     * @return bool Whether the person got a turn
     */
    public static function claim(int $userId, GroqUseKind $kind, int $limit): bool
    {
        $db = static::getDB();
        $lockName = "groq_uses_$userId";
        $stmt = $db->prepare('SELECT GET_LOCK(:name, 5)');
        $stmt->execute([':name' => $lockName]);
        if (!$stmt->fetchColumn()) {
            return false;
        }

        try {
            if (static::isUsedUp($userId, $kind, $limit)) {
                return false;
            }

            $db->prepare('DELETE FROM groq_uses WHERE user_id = :user_id AND used_at <= NOW() - INTERVAL 1 MINUTE')
                ->execute([':user_id' => $userId]);
            $db->prepare('INSERT INTO groq_uses (user_id, kind) VALUES (:user_id, :kind)')
                ->execute([':user_id' => $userId, ':kind' => $kind->value]);

            return true;
        } finally {
            $db->prepare('SELECT RELEASE_LOCK(:name)')->execute([':name' => $lockName]);
        }
    }

    /**
     * How many times the person asked Groq for this in the last minute
     */
    protected static function countInLastMinute(int $userId, GroqUseKind $kind): int
    {
        $stmt = static::getDB()->prepare('SELECT COUNT(*) FROM groq_uses
                                           WHERE user_id = :user_id AND kind = :kind AND used_at > NOW() - INTERVAL 1 MINUTE');
        $stmt->bindValue(':user_id', $userId, PDO::PARAM_INT);
        $stmt->bindValue(':kind', $kind->value, PDO::PARAM_STR);
        $stmt->execute();

        return (int) $stmt->fetchColumn();
    }
}

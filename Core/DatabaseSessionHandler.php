<?php

namespace Core;

use PDO;

/**
 * Keeps PHP sessions in the database instead of files inside the container
 *
 * Free hosts stop the container when nobody visits and start a fresh one
 * later, which wipes session files and logs everyone out. Rows in the
 * sessions table survive that
 */
class DatabaseSessionHandler extends Model implements \SessionHandlerInterface, \SessionUpdateTimestampHandlerInterface
{
    /** A session in use is marked as used at most this often, in seconds */
    const TOUCH_SECONDS = 3600;

    /**
     * Start using the store; the database needs no opening
     */
    public function open(string $path, string $name): bool
    {
        return true;
    }

    /**
     * Stop using the store; the connection stays for the rest of the request
     */
    public function close(): bool
    {
        return true;
    }

    /**
     * A session's saved data
     *
     * @return string|false '' for a session that isn't stored
     */
    public function read(string $id): string|false
    {
        $stmt = static::getDB()->prepare('SELECT data FROM sessions WHERE id = :id');
        $stmt->bindValue(':id', $id, PDO::PARAM_STR);
        $stmt->execute();

        $data = $stmt->fetchColumn();

        return $data === false ? '' : $data;
    }

    /**
     * Save a session's data and mark it as used now
     */
    public function write(string $id, string $data): bool
    {
        $stmt = static::getDB()->prepare(
            'REPLACE INTO sessions (id, data, updated_at) VALUES (:id, :data, :updated_at)'
        );
        $stmt->bindValue(':id', $id, PDO::PARAM_STR);
        $stmt->bindValue(':data', $data, PDO::PARAM_LOB);
        $stmt->bindValue(':updated_at', time(), PDO::PARAM_INT);

        return $stmt->execute();
    }

    /**
     * Whether a session id is one the site made (use_strict_mode)
     */
    public function validateId(string $id): bool
    {
        $stmt = static::getDB()->prepare('SELECT 1 FROM sessions WHERE id = :id');
        $stmt->bindValue(':id', $id, PDO::PARAM_STR);
        $stmt->execute();

        return $stmt->fetchColumn() !== false;
    }

    /**
     * Mark an unchanged session as used. PHP calls this instead of write()
     * on every request, so it only writes once per TOUCH_SECONDS
     */
    public function updateTimestamp(string $id, string $data): bool
    {
        static::touch([$id]);

        return true;
    }

    /**
     * Mark sessions as used now, unless they were within TOUCH_SECONDS, so
     * they don't expire while their pages stay open
     *
     * @param string[] $ids
     */
    public static function touch(array $ids): void
    {
        if (!$ids) {
            return;
        }

        [$idList, $idParams] = static::inList('id', $ids);
        $stmt = static::getDB()->prepare("UPDATE sessions SET updated_at = :now
                                          WHERE updated_at < :touched_before AND id IN $idList");
        $stmt->execute([':now' => time(), ':touched_before' => time() - static::TOUCH_SECONDS] + $idParams);
    }

    /**
     * Delete a session, on logout
     */
    public function destroy(string $id): bool
    {
        $stmt = static::getDB()->prepare('DELETE FROM sessions WHERE id = :id');
        $stmt->bindValue(':id', $id, PDO::PARAM_STR);

        return $stmt->execute();
    }

    /**
     * Delete the sessions unused for longer than their lifetime
     *
     * @param int $maxLifetime Seconds
     *
     * @return int|false How many were deleted
     */
    public function gc(int $maxLifetime): int|false
    {
        $stmt = static::getDB()->prepare('DELETE FROM sessions WHERE updated_at < :cutoff');
        $stmt->bindValue(':cutoff', time() - $maxLifetime, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->rowCount();
    }
}

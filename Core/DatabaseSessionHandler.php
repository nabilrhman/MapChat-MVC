<?php

namespace Core;

use PDO;

/**
 * Keeps PHP sessions in the database instead of files inside the container.
 *
 * Free hosts stop the container when nobody visits and start a fresh one
 * later, which wipes session files and logs everyone out. Rows in the
 * sessions table survive that.
 */
class DatabaseSessionHandler extends Model implements \SessionHandlerInterface, \SessionUpdateTimestampHandlerInterface
{
    public function open($path, $name): bool
    {
        return true;
    }

    public function close(): bool
    {
        return true;
    }

    public function read($id): string|false
    {
        $stmt = static::getDB()->prepare('SELECT data FROM sessions WHERE id = :id');
        $stmt->bindValue(':id', $id, PDO::PARAM_STR);
        $stmt->execute();

        $data = $stmt->fetchColumn();

        return $data === false ? '' : $data;
    }

    public function write($id, $data): bool
    {
        $stmt = static::getDB()->prepare(
            'REPLACE INTO sessions (id, data, updated_at) VALUES (:id, :data, :updated_at)'
        );
        $stmt->bindValue(':id', $id, PDO::PARAM_STR);
        $stmt->bindValue(':data', $data, PDO::PARAM_LOB);
        $stmt->bindValue(':updated_at', time(), PDO::PARAM_INT);

        return $stmt->execute();
    }

    public function validateId($id): bool
    {
        $stmt = static::getDB()->prepare('SELECT 1 FROM sessions WHERE id = :id');
        $stmt->bindValue(':id', $id, PDO::PARAM_STR);
        $stmt->execute();

        return $stmt->fetchColumn() !== false;
    }

    /**
     * Called instead of write() when the session didn't change. The chat asks
     * for messages every second, so only mark the session as used once an hour
     */
    public function updateTimestamp($id, $data): bool
    {
        $stmt = static::getDB()->prepare(
            'UPDATE sessions SET updated_at = :now WHERE id = :id AND updated_at < :now - 3600'
        );
        $stmt->bindValue(':id', $id, PDO::PARAM_STR);
        $stmt->bindValue(':now', time(), PDO::PARAM_INT);

        return $stmt->execute();
    }

    public function destroy($id): bool
    {
        $stmt = static::getDB()->prepare('DELETE FROM sessions WHERE id = :id');
        $stmt->bindValue(':id', $id, PDO::PARAM_STR);

        return $stmt->execute();
    }

    public function gc($max_lifetime): int|false
    {
        $stmt = static::getDB()->prepare('DELETE FROM sessions WHERE updated_at < :cutoff');
        $stmt->bindValue(':cutoff', time() - $max_lifetime, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->rowCount();
    }
}

<?php

namespace Core;

use PDO;

/**
 * Keeps PHP sessions in the database instead of files inside the container
 *
 * Free hosts stop the container when nobody visits and start a fresh one
 * later, which wipes session files and logs everyone out. Rows in the
 * sessions table survive that
 *
 * A request holds its session's lock from read() to close(), as PHP's own
 * file sessions do, so two requests from one person run one after the other
 * and neither writes over what the other saved
 */
class DatabaseSessionHandler extends Model implements \SessionHandlerInterface, \SessionUpdateTimestampHandlerInterface
{
    /** A session in use is marked as used at most this often, in seconds */
    const TOUCH_SECONDS = 3600;

    /**
     * How long a request waits for another request of the same session, in
     * seconds. Past it, it reads anyway rather than fail the page
     */
    const LOCK_WAIT_SECONDS = 10;

    /** The session whose lock this connection holds, or null */
    private ?string $lockedId = null;

    /**
     * @param bool $isLocking False only for a reader that never writes, like
     *                        the chat socket, which mustn't wait on a request
     */
    public function __construct(private readonly bool $isLocking = true)
    {
    }

    /**
     * Start using the store; the database needs no opening
     */
    public function open(string $path, string $name): bool
    {
        return true;
    }

    /**
     * Stop using the store, and let the next request of the session go. The
     * connection stays for the rest of the request
     */
    public function close(): bool
    {
        $this->releaseLock();

        return true;
    }

    /**
     * A session's saved data, read once any other request of it is done
     *
     * @return string|false '' for a session that isn't stored
     */
    public function read(string $id): string|false
    {
        if ($this->isLocking) {
            $this->takeLock($id);
        }

        $stmt = static::getDB()->prepare('SELECT data FROM sessions WHERE id = :id');
        $stmt->bindValue(':id', $id, PDO::PARAM_STR);
        $stmt->execute();

        $data = $stmt->fetchColumn();

        return $data === false ? '' : $data;
    }

    /**
     * Wait for the session's lock, and keep it until close()
     */
    private function takeLock(string $id): void
    {
        $this->releaseLock();

        $stmt = static::getDB()->prepare('SELECT GET_LOCK(:name, :seconds)');
        $stmt->bindValue(':name', static::lockNameOf($id), PDO::PARAM_STR);
        $stmt->bindValue(':seconds', static::LOCK_WAIT_SECONDS, PDO::PARAM_INT);
        $stmt->execute();

        if ((int) $stmt->fetchColumn() === 1) {
            $this->lockedId = $id;
        } else {
            error_log('Session lock not free after ' . static::LOCK_WAIT_SECONDS . ' s; reading without it');
        }
    }

    /**
     * Let go of the session's lock, if this connection holds one
     */
    private function releaseLock(): void
    {
        if ($this->lockedId === null) {
            return;
        }

        $stmt = static::getDB()->prepare('SELECT RELEASE_LOCK(:name)');
        $stmt->bindValue(':name', static::lockNameOf($this->lockedId), PDO::PARAM_STR);
        $stmt->execute();
        $this->lockedId = null;
    }

    /**
     * The name of a session's lock, the same for every request of it. A
     * hash, as MySQL's lock names are 64 characters at most and a session
     * id may be longer
     */
    public static function lockNameOf(string $id): string
    {
        return 'session_' . sha1($id);
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

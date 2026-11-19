<?php

namespace Tests\Core;

use App\Config;
use Core\DatabaseSessionHandler;
use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\TestDb;

/**
 * Sessions kept in the database, and the lock that makes two requests of one
 * session take turns. The other request is played by a second connection,
 * as MySQL's locks belong to a connection
 */
class DatabaseSessionHandlerTest extends TestCase
{
    private string $sessionId;

    /** Another request's connection */
    private PDO $otherRequest;

    protected function setUp(): void
    {
        $this->sessionId = bin2hex(random_bytes(16));
        TestDb::get()->prepare('INSERT INTO sessions (id, data, updated_at) VALUES (?, ?, ?)')
            ->execute([$this->sessionId, 'user_id|i:7;', time()]);

        $dsn = 'mysql:host=' . Config::get('DB_HOST', 'localhost')
            . ';port=' . Config::get('DB_PORT', '3306')
            . ';dbname=' . Config::get('DB_NAME', 'mapchat');
        $this->otherRequest = new PDO($dsn, Config::get('DB_USER', 'root'), Config::get('DB_PASSWORD', ''));
    }

    protected function tearDown(): void
    {
        TestDb::get()->prepare('DELETE FROM sessions WHERE id = ?')->execute([$this->sessionId]);
    }

    /**
     * Whether the other request could take the session's lock at once. It
     * lets go of it again straight away
     */
    private function canOtherRequestLock(): bool
    {
        $name = DatabaseSessionHandler::lockNameOf($this->sessionId);
        $stmt = $this->otherRequest->prepare('SELECT GET_LOCK(?, 0)');
        $stmt->execute([$name]);
        $isLocked = (int) $stmt->fetchColumn() === 1;
        if ($isLocked) {
            $this->otherRequest->prepare('SELECT RELEASE_LOCK(?)')->execute([$name]);
        }

        return $isLocked;
    }

    public function testAReadHoldsTheSessionUntilItCloses(): void
    {
        $handler = new DatabaseSessionHandler();

        $this->assertSame('user_id|i:7;', $handler->read($this->sessionId));
        $this->assertFalse($this->canOtherRequestLock());

        $handler->close();
        $this->assertTrue($this->canOtherRequestLock());
    }

    public function testAReaderWithoutTheLockNeitherTakesNorWaitsForIt(): void
    {
        $name = DatabaseSessionHandler::lockNameOf($this->sessionId);
        $this->otherRequest->prepare('SELECT GET_LOCK(?, 0)')->execute([$name]);

        $startedAt = microtime(true);
        $data = (new DatabaseSessionHandler(isLocking: false))->read($this->sessionId);

        $this->assertSame('user_id|i:7;', $data);
        $this->assertLessThan(1, microtime(true) - $startedAt);
        $this->otherRequest->prepare('SELECT RELEASE_LOCK(?)')->execute([$name]);
    }

    public function testALockNameFitsMySqlsLimitForTheLongestSessionId(): void
    {
        $this->assertLessThanOrEqual(64, strlen(DatabaseSessionHandler::lockNameOf(str_repeat('a', 256))));
    }
}

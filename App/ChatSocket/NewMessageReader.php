<?php

namespace App\ChatSocket;

use App\Models\Chat;

/**
 * Reads the ids of new messages in order, for the chat socket (Hub) and the
 * chat worker's Web Push. Ids are taken when a send starts but show only
 * once it commits, so a later id can show first: the reader stops at a
 * missing id for a moment (GAP_SECONDS) rather than skip a message that's
 * still coming
 */
class NewMessageReader
{
    /**
     * A missing id (a send that took it hasn't committed yet, or a failed or
     * rolled-back insert that never will) is waited for this long before
     * reading on without it. A send commits in milliseconds
     */
    const GAP_SECONDS = 2;

    /** When the reader first stopped at a missing id, or null */
    private ?float $gapSince = null;

    /**
     * Start reading after a message
     *
     * @param int $lastReadId The newest id already read: new messages are after it
     */
    public function __construct(private int $lastReadId)
    {
    }

    /**
     * The newest id read so far
     */
    public function getLastReadId(): int
    {
        return $this->lastReadId;
    }

    /**
     * The ids saved since the last read, in order, up to a missing id that
     * hasn't been waited out yet
     *
     * @param int $limit The most to read in one go
     *
     * @return int[]
     */
    public function readNewIds(int $limit): array
    {
        $readIds = [];

        foreach (Chat::getIdsAfter($this->lastReadId, $limit) as $id) {
            if ($id !== $this->lastReadId + 1) {
                $this->gapSince ??= $this->now();
                if ($this->now() - $this->gapSince < static::GAP_SECONDS) {
                    break;
                }
            }
            $this->gapSince = null;
            $readIds[] = $id;
            $this->lastReadId = $id;
        }

        return $readIds;
    }

    /**
     * The time now, in seconds. Tests move it forward
     */
    protected function now(): float
    {
        return microtime(true);
    }
}

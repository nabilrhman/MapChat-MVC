<?php

namespace App\ChatSocket;

/**
 * How much of something costly one person may ask the chat socket for: a
 * burst at once, then a steady rate. Work past it waits its turn rather than
 * being refused, so a page only ever goes slower
 */
class RateBudget
{
    /** What's left to spend now; it refills at perSecond up to burst */
    private float $left;

    /** When $left was last worked out, as microtime */
    private float $countedAt;

    /**
     * A full budget
     *
     * @param int $burst The most that can go at once
     * @param float $perSecond How fast it refills
     */
    public function __construct(private readonly int $burst, private readonly float $perSecond)
    {
        $this->left = $burst;
        $this->countedAt = microtime(true);
    }

    /**
     * Spend one if there's one to spend
     *
     * @return bool Whether it was spent
     */
    public function trySpend(): bool
    {
        $this->refill();
        if ($this->left < 1) {
            return false;
        }

        $this->left -= 1;

        return true;
    }

    /**
     * How long until there's one to spend, in seconds
     */
    public function secondsUntilNext(): float
    {
        $this->refill();

        return max(0, (1 - $this->left) / $this->perSecond);
    }

    /**
     * Whether it has refilled all the way, and so is the same as a new one
     */
    public function isFull(): bool
    {
        $this->refill();

        return $this->left >= $this->burst;
    }

    /**
     * Add what the time since the last count has earned
     */
    private function refill(): void
    {
        $now = microtime(true);
        $this->left = min($this->burst, $this->left + ($now - $this->countedAt) * $this->perSecond);
        $this->countedAt = $now;
    }
}

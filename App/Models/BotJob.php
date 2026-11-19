<?php

namespace App\Models;

use App\Bot\BotJobKind;

/**
 * One of the answers MapChat or a demo person owes someone (Bot), as a row
 * of mapchat_jobs
 */
readonly class BotJob
{
    /**
     * Hold a job's values
     *
     * @param string $email Who it's for
     * @param string $speaker Who answers: MapChat, or a demo person
     * @param int|null $chatId The message it answers; null for a greeting
     * @param string|null $reply A private reply already written, waiting until it would have been typed
     * @param string|null $replyOriginal The speaker's own words, when $reply is their translation
     * @param string|null $answeredOriginal What the answered message's sender typed, when they translated it
     */
    public function __construct(
        public int $id,
        public BotJobKind $kind,
        public string $email,
        public string $speaker,
        public ?int $chatId,
        public ?string $reply,
        public ?string $replyOriginal = null,
        public ?string $answeredOriginal = null,
    ) {
    }

    /**
     * A job as the database row gives it
     *
     * @param array<string, ?string> $row A mapchat_jobs row, with the answered
     *                                    message's original_body as answered_original
     */
    public static function fromRow(array $row): self
    {
        return new self(
            (int) $row['id'],
            BotJobKind::from($row['kind']),
            $row['email'],
            $row['speaker'],
            $row['chat_id'] === null ? null : (int) $row['chat_id'],
            $row['reply'],
            $row['reply_original'],
            $row['answered_original'],
        );
    }
}

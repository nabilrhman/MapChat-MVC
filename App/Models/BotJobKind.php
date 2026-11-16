<?php

namespace App\Models;

/**
 * What a MapChat job (Bot) answers, as mapchat_jobs.kind stores it
 */
enum BotJobKind: string
{
    /** Hello to someone who walked into a quiet room */
    case Greeting = 'greet';

    /** An answer in the open room to someone nobody else answered, or who's talking with the speaker */
    case Reply = 'reply';

    /** An answer in the open room to someone who @mentioned the speaker */
    case Mention = 'mention';

    /** An answer in a private chat */
    case PrivateReply = 'dm';

    /**
     * Whether its answer goes to a private chat rather than the open room
     */
    public function isPrivate(): bool
    {
        return $this === self::PrivateReply;
    }
}

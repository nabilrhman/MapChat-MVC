<?php

namespace App\ChatSocket;

/**
 * What a chat page can say over its socket (Hub::receive), as its "type"
 */
enum MessageFromPage: string
{
    /** Which message it has last, so it can be caught up from there */
    case Hello = 'hello';

    /** A message to save and send out */
    case Send = 'send';

    /** A private chat is read up to a message */
    case Read = 'read';

    /** The page of a tab's messages before one, as the reader scrolls up */
    case Older = 'older';

    /** It's typing to someone, or stopped */
    case Typing = 'typing';

    /** Is the connection still there */
    case Ping = 'ping';

    /**
     * Whether a page may say it before hello
     */
    public function isAllowedBeforeHello(): bool
    {
        return $this === self::Hello || $this === self::Ping;
    }
}

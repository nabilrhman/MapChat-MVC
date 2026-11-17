<?php

namespace App\ChatSocket;

/**
 * What the chat socket tells a page, as its "type". json_encode writes each
 * as its value
 */
enum MessageToPage: string
{
    /** The answer to hello: the build and who's here */
    case Welcome = 'welcome';

    /** Caught up: what comes next is the live stream */
    case Live = 'live';

    /** Messages to show */
    case Chats = 'chats';

    /** A message it sent was saved */
    case Sent = 'sent';

    /** A message it sent wasn't saved, and why */
    case Refused = 'refused';

    /** A private chat was read on another of the person's pages */
    case Read = 'read';

    /** The older messages it asked for */
    case Older = 'older';

    /** Someone is typing to this person, or stopped */
    case Typing = 'typing';

    /** The answer to ping */
    case Pong = 'pong';

    /** Someone came online or went */
    case Presence = 'presence';
}

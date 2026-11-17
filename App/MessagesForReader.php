<?php

namespace App;

use App\Models\Bot;
use App\Models\Chat;
use App\Models\Conversation;
use App\Models\User;

/**
 * Message rows as App/chat.html shows them to one reader: which tab each
 * goes in, whose name is a button, and which mention the reader
 */
class MessagesForReader
{
    /**
     * The message rows, each with what chat.html shows for this reader
     * added (see rowFor)
     *
     * @param array[] $chats Message rows from App\Models\Chat
     *
     * @return array[]
     */
    public static function rowsOf(array $chats, User $reader): array
    {
        $readerName = new PersonName($reader->name, $reader->nickname, $reader->is_guest);
        $canMessageMapChat = Bot::canReply();

        return array_map(
            fn (array $chat): array => static::rowFor($chat, $reader->id, $readerName, $canMessageMapChat),
            $chats
        );
    }

    /**
     * One message row with is_mine, conversation ("everyone" or the other
     * person's id), partner (their shown name; '' in the room),
     * sender_name, can_message, mentions_me, is_in_room and
     * shows_sender_time added
     *
     * @param array $chat A message row from App\Models\Chat
     * @param bool $canMessageMapChat Whether MapChat can write back now (Bot::canReply)
     */
    protected static function rowFor(array $chat, ?int $readerId, PersonName $readerName, bool $canMessageMapChat): array
    {
        $isMine = $chat['user_id'] === $readerId;
        $isInRoom = $chat['to_user_id'] === null;
        $isFromMapChat = $chat['email'] === Chat::BOT_EMAIL;

        $chat['is_mine'] = $isMine;
        $chat['is_in_room'] = $isInRoom;
        $chat['conversation'] = static::conversationOf($chat, $isMine)->pageName();
        $chat['partner'] = static::partnerNameOf($chat, $isMine);
        $chat['sender_name'] = Mention::shownName($chat['name'], $chat['nickname'], $chat['is_guest'] ?? false);
        // Only in the open room; inside a private chat you're already talking
        // to them. MapChat only while it can write back
        $chat['can_message'] = $isInRoom && !$isMine && (!$isFromMapChat || $canMessageMapChat);
        // @mentions are for the open room, like the button
        $chat['mentions_me'] = $isInRoom && !$isMine && Mention::isMentionedIn($chat['body'], $readerName);
        // A private chat's header already shows the other person's time
        $chat['shows_sender_time'] = $isInRoom && isset($chat['lng']);

        return $chat;
    }

    /**
     * Which tab the message belongs in, for this reader
     *
     * @param array $chat A message row from App\Models\Chat
     */
    protected static function conversationOf(array $chat, bool $isMine): Conversation
    {
        if ($chat['to_user_id'] === null) {
            return Conversation::room();
        }

        return Conversation::with($isMine ? $chat['to_user_id'] : $chat['user_id']);
    }

    /**
     * The shown name of the other person in a private chat, or '' in the room
     *
     * @param array $chat A message row from App\Models\Chat
     */
    protected static function partnerNameOf(array $chat, bool $isMine): string
    {
        if ($chat['to_user_id'] === null) {
            return '';
        }
        if ($isMine) {
            return Mention::shownName($chat['to_name'], $chat['to_nickname'], $chat['to_is_guest'] ?? false);
        }

        return Mention::shownName($chat['name'], $chat['nickname'], $chat['is_guest'] ?? false);
    }
}

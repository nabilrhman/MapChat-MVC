<?php

namespace App;

use App\Models\Conversation;
use App\Models\PushSubscription;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;

/**
 * Web Push: notifications for private messages and @mentions that reach a
 * browser even with MapChat closed. The chat worker sends them for each new
 * message (bin/chat-worker.php); public/sw.js shows them. Off until the
 * VAPID keys are set (.env.example)
 */
class Push
{
    /** Longest message text a notification shows */
    const BODY_LENGTH = 120;

    /** Seconds to wait on a push service before giving up on it */
    const TIMEOUT = 5;

    /**
     * Whether Web Push is set up
     */
    public static function isEnabled(): bool
    {
        return Config::get('VAPID_PUBLIC_KEY') !== null && Config::get('VAPID_PRIVATE_KEY') !== null;
    }

    /**
     * The key browsers subscribe with, for the pages
     */
    public static function publicKey(): string
    {
        return (string) Config::get('VAPID_PUBLIC_KEY', '');
    }

    /**
     * Push these new messages to the browsers they're for
     *
     * @param array[] $chats As Chat::getByIds gives them
     */
    public static function sendFor(array $chats): void
    {
        if (!static::isEnabled() || !$chats) {
            return;
        }

        $subscriptions = PushSubscription::getAll();
        if (!$subscriptions) {
            return;
        }

        $webPush = new WebPush([
            'VAPID' => [
                'subject' => Config::get('VAPID_SUBJECT', Config::appUrl()),
                'publicKey' => Config::get('VAPID_PUBLIC_KEY'),
                'privateKey' => Config::get('VAPID_PRIVATE_KEY'),
            ],
        ], [], static::TIMEOUT);

        foreach ($chats as $chat) {
            foreach (static::recipientsOf($chat, $subscriptions) as $subscription) {
                $webPush->queueNotification(
                    Subscription::create([
                        'endpoint' => $subscription['endpoint'],
                        'keys' => ['p256dh' => $subscription['p256dh'], 'auth' => $subscription['auth']],
                        // The standard encoding every current browser reads (RFC 8291)
                        'contentEncoding' => 'aes128gcm',
                    ]),
                    json_encode(static::payloadOf($chat, $subscription))
                );
            }
        }

        foreach ($webPush->flush() as $report) {
            // Unsubscribed, or the browser's data was cleared: stop trying it
            if ($report->isSubscriptionExpired()) {
                PushSubscription::deleteGone($report->getEndpoint());
            } elseif (!$report->isSuccess()) {
                error_log('Web Push failed: ' . $report->getReason());
            }
        }
    }

    /**
     * The subscribed browsers a message is for: the other person's in a
     * private chat, and in the room, those of whoever it @mentions. Never
     * the sender's own
     *
     * @param array{email: string, to_email: ?string, body: string} $chat
     * @param array[] $subscriptions As PushSubscription::getAll gives them
     *
     * @return array[] The subscriptions it's for
     */
    public static function recipientsOf(array $chat, array $subscriptions): array
    {
        return array_values(array_filter($subscriptions, function (array $subscription) use ($chat): bool {
            if ($subscription['email'] === $chat['email']) {
                return false;
            }
            if ($chat['to_email'] !== null) {
                return $subscription['email'] === $chat['to_email'];
            }

            return Mention::isMentionedIn($chat['body'], PersonName::fromRow($subscription));
        }));
    }

    /**
     * What public/sw.js shows for a message
     *
     * @param array $chat As Chat::getByIds gives it, with ids and flags cast
     * @param array $subscription Whose browser it's for
     *
     * @return array{title: string, body: string, icon: string, tag: string, conversation: string, name: string}
     */
    public static function payloadOf(array $chat, array $subscription): array
    {
        $name = PersonName::fromRow($chat)->shown();
        $isPrivate = $chat['to_email'] !== null;
        $body = $chat['body'];
        if (mb_strlen($body) > static::BODY_LENGTH) {
            $body = mb_substr($body, 0, static::BODY_LENGTH - 1) . '…';
        }
        // A private chat is the sender's tab
        $conversation = $isPrivate ? Conversation::with($chat['user_id']) : Conversation::room();

        return [
            'title' => $isPrivate ? $name : "$name mentioned you",
            'body' => $body,
            'icon' => Avatar::src($chat['user_id'], $chat['name'], $chat['avatar'], $chat['is_demo']),
            // One per chat: a newer message replaces the last one
            'tag' => 'chat-' . $conversation->pageName(),
            'conversation' => $conversation->pageName(),
            'name' => $name,
        ];
    }
}

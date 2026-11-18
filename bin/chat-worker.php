<?php

/**
 * The chat worker: what open chat pages used to set off with each poll, now
 * that they listen on the chat socket instead. Every second it runs
 * MapChat's due replies (Bot::runDue), and every minute it adds the demo
 * people's lines that are due (DemoChat::catchUp) and deletes guest
 * accounts whose day is up, then signals the socket to send out what's new.
 * docker/start.sh runs it and starts it again if it stops. Pages on the
 * HTTP fallback still run the replies and the demo lines from their polls;
 * locks in each keep two from running at once. Each round it also sends
 * Web Push for the messages saved since the last (App\Notify\PushNotifications), from
 * whichever way they came
 */

use App\ChatSocket\NewMessageReader;
use App\ChatSocket\Signal;
use App\Models\Bot;
use App\Models\Chat;
use App\Models\DemoChat;
use App\Models\User;
use App\Notify\PushNotifications;

require dirname(__DIR__) . '/vendor/autoload.php';

const ROUND_SECONDS = 1;
// Messages pushed per round at most; a burst catches up over the next rounds
const PUSH_BATCH = 50;

$isStopping = false;
pcntl_async_signals(true);
pcntl_signal(SIGTERM, function () use (&$isStopping) {
    $isStopping = true;
});
pcntl_signal(SIGINT, function () use (&$isStopping) {
    $isStopping = true;
});

$demoCaughtUpAt = 0;
// Made on the first round push is on, from the newest message: a restart never pushes old messages again
$messagesToPush = null;

while (!$isStopping) {
    try {
        Bot::runDue();

        if (DemoChat::isCatchUpDue($demoCaughtUpAt)) {
            $demoCaughtUpAt = time();
            DemoChat::catchUp();
            // The socket's session check then closes their pages
            User::deleteExpiredGuests();
        }

        // Bot replies signal as they're saved (Chat::save); the demo's go in by SQL
        Signal::newMessages();

        if (PushNotifications::isEnabled()) {
            $messagesToPush ??= new NewMessageReader(Chat::getNewestId());
            // Moved on before sending, so a failing message isn't pushed again every round
            PushNotifications::sendFor(Chat::getByIds($messagesToPush->readNewIds(PUSH_BATCH)));
        }
    } catch (PDOException $e) {
        error_log('Chat worker: database error, reconnecting: ' . $e->getMessage());
        Core\Model::disconnectDB();
    }

    sleep(ROUND_SECONDS);
}

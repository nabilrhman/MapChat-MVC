<?php

/**
 * The chat worker: what open chat pages used to set off with each poll, now
 * that they listen on the chat socket instead. Every second it runs
 * MapChat's due replies (Bot::runDue), and every minute it adds the demo
 * people's lines that are due (DemoChat::catchUp), then signals the socket
 * to send them out. docker/start.sh runs it and starts it again if it stops.
 * Pages on the HTTP fallback still do both from their polls; locks in each
 * keep two from running at once. Each round it also sends Web Push for the
 * messages saved since the last (App\Push), from whichever way they came.
 */

use App\ChatSocket\Signal;
use App\Models\Bot;
use App\Models\Chat;
use App\Models\DemoChat;
use App\Push;

require dirname(__DIR__) . '/vendor/autoload.php';

const ROUND_SECONDS = 1;
const DEMO_SECONDS = 60;
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

$demoAt = 0;
// From now on: a restart never pushes old messages again
$pushedUpTo = null;

while (!$isStopping) {
    try {
        Bot::runDue();

        if (DemoChat::isFast() || time() - $demoAt >= DEMO_SECONDS) {
            $demoAt = time();
            DemoChat::catchUp();
        }

        // Bot replies signal as they're saved (Chat::send); the demo's go in by SQL
        Signal::newMessages();

        if (Push::isEnabled()) {
            $pushedUpTo ??= Chat::getNewestId();
            $ids = Chat::getIdsAfter($pushedUpTo, PUSH_BATCH);
            if ($ids) {
                // Moved on first, so a failing message isn't pushed again every round
                $pushedUpTo = end($ids);
                Push::sendFor(Chat::getByIds($ids));
            }
        }
    } catch (PDOException $e) {
        error_log('Chat worker: database error, reconnecting: ' . $e->getMessage());
        Core\Model::disconnectDB();
    }

    sleep(ROUND_SECONDS);
}

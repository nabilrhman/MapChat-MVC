<?php

/**
 * The chat worker: what open chat pages used to set off with each poll, now
 * that they listen on the chat socket instead. Every second it runs
 * MapChat's due replies (Bot::runDue), and every minute it adds the demo
 * people's lines that are due (DemoChat::catchUp), then signals the socket
 * to send them out. docker/start.sh runs it and starts it again if it stops.
 * Pages on the HTTP fallback still do both from their polls; locks in each
 * keep two from running at once.
 */

use App\ChatSocket\Signal;
use App\Models\Bot;
use App\Models\DemoChat;

require dirname(__DIR__) . '/vendor/autoload.php';

const ROUND_SECONDS = 1;
const DEMO_SECONDS = 60;

$isStopping = false;
pcntl_async_signals(true);
pcntl_signal(SIGTERM, function () use (&$isStopping) {
    $isStopping = true;
});
pcntl_signal(SIGINT, function () use (&$isStopping) {
    $isStopping = true;
});

$demoAt = 0;

while (!$isStopping) {
    try {
        Bot::runDue();

        if (DemoChat::isFast() || time() - $demoAt >= DEMO_SECONDS) {
            $demoAt = time();
            DemoChat::catchUp();
        }

        // Bot replies signal as they're saved (Chat::send); the demo's go in by SQL
        Signal::newMessages();
    } catch (PDOException $e) {
        error_log('Chat worker: database error, reconnecting: ' . $e->getMessage());
        Core\Model::disconnectDB();
    }

    sleep(ROUND_SECONDS);
}

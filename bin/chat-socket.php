<?php

/**
 * The chat socket: one WebSocket per open chat page (see App\ChatSocket\Hub).
 * Listens on 127.0.0.1:CHAT_SOCKET_PORT (default 8090); Apache passes
 * wss://…/ws to it. docker/start.sh runs it and starts it again if it stops.
 * On SIGTERM or SIGINT it closes every page with "restarting" and exits; the
 * pages reconnect and pick up where they were.
 */

use Amp\Http\Server\DefaultErrorHandler;
use Amp\Http\Server\Middleware\ForwardedHeaderType;
use Amp\Http\Server\SocketHttpServer;
use Amp\Websocket\ConstantRateLimit;
use Amp\Websocket\Parser\Rfc6455ParserFactory;
use Amp\Websocket\PeriodicHeartbeatQueue;
use Amp\Websocket\Server\Rfc6455ClientFactory;
use Amp\Websocket\Server\Websocket;
use App\ChatSocket\Handshake;
use App\ChatSocket\Hub;
use App\Config;
use Revolt\EventLoop;

require dirname(__DIR__) . '/vendor/autoload.php';

// Logs go to stderr. Nothing may print to stdout: PHP won't read sessions
// (App\ChatSocket\Sessions) once output has started

/**
 * Warnings and worse to the container's log
 */
$logger = new class extends Psr\Log\AbstractLogger {
    public function log($level, Stringable|string $message, array $context = []): void
    {
        if (in_array($level, ['debug', 'info', 'notice'], true)) {
            return;
        }
        $exception = isset($context['exception']) ? ': ' . $context['exception']->getMessage() : '';
        error_log("Chat socket $level: $message$exception");
    }
};

$port = Config::chatSocketPort();
$host = Config::get('CHAT_SOCKET_HOST', '127.0.0.1');

// Behind Apache, every connection comes from 127.0.0.1, so there's no limit per address
$httpServer = SocketHttpServer::createForBehindProxy($logger, ForwardedHeaderType::XForwardedFor, ['127.0.0.1', '::1'], enableCompression: false);
$httpServer->expose("$host:$port");

$hub = new Hub();

$clientFactory = new Rfc6455ClientFactory(
    // A ping every 10 s; three unanswered and the page is gone
    heartbeatQueue: new PeriodicHeartbeatQueue(queuedPingLimit: 3, heartbeatPeriod: 10),
    // Far above a person's typing and sending; reading a faster page slows down to this
    rateLimit: new ConstantRateLimit(bytesPerSecondLimit: 65536, framesPerSecondLimit: 20),
    // The longest chat message is under 2 KB as JSON
    parserFactory: new Rfc6455ParserFactory(textOnly: true, validateUtf8: true, messageSizeLimit: 16384, frameSizeLimit: 16384),
);
$websocket = new Websocket($httpServer, $logger, new Handshake($hub), $hub, null, $clientFactory);

$httpServer->start($websocket, new DefaultErrorHandler());
$hub->start();

// Saved messages are signalled over UDP on the same port (App\ChatSocket\Signal)
$signals = stream_socket_server("udp://127.0.0.1:$port", $errorCode, $errorMessage, STREAM_SERVER_BIND);
if (!$signals) {
    throw new RuntimeException("Can't listen for new-message signals on udp port $port: $errorMessage");
}
stream_set_blocking($signals, false);
EventLoop::onReadable($signals, function () use ($signals, $hub) {
    // Take every waiting signal; one read of the database covers them all
    do {
        $datagram = @stream_socket_recvfrom($signals, 16);
    } while ($datagram !== false && $datagram !== '');
    $hub->hearNewMessages();
});

error_log("Chat socket listening on $host:$port");

$signal = Amp\trapSignal([SIGINT, SIGTERM]);
error_log("Chat socket stopping (signal $signal)");

$hub->closeAll();
$httpServer->stop();

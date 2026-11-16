<?php

namespace App\ChatSocket;

use App\Config;

/**
 * Tells the chat socket that a message was saved, so it reads it at once
 * instead of on its next look (Hub::TAIL_SECONDS). Sent from every process
 * that saves messages: PHP-FPM, the chat worker and the socket itself
 */
class Signal
{
    /**
     * Send the signal. A lost one only delays the message a little, so
     * nothing waits for it and nothing fails without a listener
     *
     * @return void
     */
    public static function newMessages()
    {
        $socket = @stream_socket_client('udp://127.0.0.1:' . Config::chatSocketPort(), $errorCode, $errorMessage, 0);
        if ($socket) {
            @fwrite($socket, 'm');
            fclose($socket);
        }
    }
}

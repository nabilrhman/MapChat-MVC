<?php

namespace App\ChatSocket;

use App\Config;

/**
 * Tells the chat socket that a message was saved, so it reads it at once
 * instead of on its next look (Hub::TAIL_SECONDS). Sent from every process
 * that saves messages: PHP-FPM, the chat worker and the socket itself. Also
 * carries the worker's typing (typing())
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
        static::send('m');
    }

    /**
     * Tell a person's open pages that MapChat or a demo person is writing
     * them a private reply, or has stopped without one. The chat worker
     * writes those replies and has no socket of its own
     *
     * @param string $fromEmail Who's typing
     * @param string $toEmail Who to tell
     * @param bool $isTyping
     *
     * @return void
     */
    public static function typing($fromEmail, $toEmail, $isTyping)
    {
        static::send(json_encode(['typing' => ['from' => $fromEmail, 'to' => $toEmail, 'isTyping' => $isTyping]]));
    }

    /**
     * @param string $datagram
     *
     * @return void
     */
    private static function send($datagram)
    {
        $socket = @stream_socket_client('udp://127.0.0.1:' . Config::chatSocketPort(), $errorCode, $errorMessage, 0);
        if ($socket) {
            @fwrite($socket, $datagram);
            fclose($socket);
        }
    }
}

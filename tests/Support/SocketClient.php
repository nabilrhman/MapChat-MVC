<?php

namespace Tests\Support;

/**
 * A WebSocket client for the chat socket tests, sending what a chat page
 * sends: masked text frames of JSON. Answers the server's pings, and puts a
 * message split over several frames back together
 */
class SocketClient
{
    /** @var resource */
    private $stream;
    private string $unread = '';

    /** The handshake's HTTP status: 101 when let in */
    public int $status = 0;

    /** The close code the server sent, once it closed */
    public ?int $closeCode = null;

    /** Every message received, in order */
    public array $messages = [];

    private int $nextMessage = 0;

    /**
     * @param string $address host:port
     * @param string|null $sessionId Sent as the session cookie
     * @param string|null $origin Defaults to the address itself, as a page on the site
     */
    public function __construct(string $address, ?string $sessionId, ?string $origin = null)
    {
        $this->stream = stream_socket_client("tcp://$address", $errorCode, $errorMessage, 5);
        if (!$this->stream) {
            throw new \RuntimeException("Can't connect to $address: $errorMessage");
        }

        $headers = [
            'GET /ws HTTP/1.1',
            "Host: $address",
            'Upgrade: websocket',
            'Connection: Upgrade',
            'Sec-WebSocket-Version: 13',
            'Sec-WebSocket-Key: ' . base64_encode(random_bytes(16)),
            'Origin: ' . ($origin ?? "http://$address"),
        ];
        if ($sessionId !== null) {
            $headers[] = 'Cookie: ' . session_name() . "=$sessionId";
        }
        fwrite($this->stream, implode("\r\n", $headers) . "\r\n\r\n");

        $response = '';
        while (!str_contains($response, "\r\n\r\n")) {
            $chunk = $this->readSome(5);
            if ($chunk === null) {
                break;
            }
            $response .= $chunk;
        }
        [$head, $this->unread] = array_pad(explode("\r\n\r\n", $response, 2), 2, '');
        $this->status = (int) (explode(' ', $head)[1] ?? 0);
    }

    /**
     * Say hello as a page does, starting after a message id, and wait until
     * caught up
     *
     * @return array The welcome message
     */
    public function hello(int $after): array
    {
        $this->send(['type' => 'hello', 'after' => $after]);
        $welcome = $this->waitFor('welcome');
        $this->waitFor('live');

        return $welcome;
    }

    /**
     * Send a message as JSON
     */
    public function send(array $message): void
    {
        $this->sendText(json_encode($message));
    }

    /**
     * Send a text frame, masked as a browser sends it
     */
    public function sendText(string $text, int $opcode = 0x1): void
    {
        $length = strlen($text);
        $mask = random_bytes(4);

        if ($length < 126) {
            $header = pack('CC', 0x80 | $opcode, 0x80 | $length);
        } elseif ($length < 65536) {
            $header = pack('CCn', 0x80 | $opcode, 0x80 | 126, $length);
        } else {
            $header = pack('CCJ', 0x80 | $opcode, 0x80 | 127, $length);
        }

        @fwrite($this->stream, $header . $mask . ($text ^ str_repeat($mask, intdiv($length, 4) + 1)));
    }

    /**
     * The next message of this type (and matching the test, if given), from
     * those not looked at yet. Others before it stay for later. Null if none
     * comes in time or the socket closes
     */
    public function waitFor(string $type, float $seconds = 5, ?callable $matches = null): ?array
    {
        $deadline = microtime(true) + $seconds;
        $looked = $this->nextMessage;

        while (true) {
            for (; $looked < count($this->messages); $looked++) {
                $message = $this->messages[$looked];
                if ($message['type'] === $type && ($matches === null || $matches($message))) {
                    array_splice($this->messages, $looked, 1);
                    return $message;
                }
            }
            if ($this->closeCode !== null || !$this->readMessage($deadline - microtime(true))) {
                return null;
            }
        }
    }

    /**
     * Every message received within the time, from those not looked at yet
     */
    public function collect(float $seconds): array
    {
        $deadline = microtime(true) + $seconds;
        while ($this->closeCode === null && $this->readMessage($deadline - microtime(true))) {
        }

        $collected = array_slice($this->messages, $this->nextMessage);
        $this->nextMessage = count($this->messages);

        return $collected;
    }

    /**
     * Wait for the server to close the socket; its close code, or null
     */
    public function waitForClose(float $seconds = 5): ?int
    {
        $deadline = microtime(true) + $seconds;
        while ($this->closeCode === null && $this->readMessage($deadline - microtime(true))) {
        }

        return $this->closeCode;
    }

    /**
     * Close the connection
     */
    public function close(): void
    {
        if (is_resource($this->stream)) {
            fclose($this->stream);
        }
    }

    /**
     * Read until one more message is in $messages, or the socket closes
     *
     * @return bool Whether one arrived in time
     */
    private function readMessage(float $seconds): bool
    {
        $deadline = microtime(true) + $seconds;
        $fragments = '';

        while (true) {
            $frame = $this->takeFrame();
            if ($frame === null) {
                $chunk = $this->readSome($deadline - microtime(true));
                if ($chunk === null) {
                    return false;
                }
                $this->unread .= $chunk;
                continue;
            }

            [$isFinal, $opcode, $payload] = $frame;
            if ($opcode === 0x9) {
                $this->sendText($payload, 0xA);
            } elseif ($opcode === 0x8) {
                $this->closeCode = strlen($payload) >= 2 ? unpack('n', $payload)[1] : 1005;
                return false;
            } elseif ($opcode === 0x1 || $opcode === 0x0) {
                $fragments .= $payload;
                if ($isFinal) {
                    $this->messages[] = json_decode($fragments, true);
                    return true;
                }
            }
        }
    }

    /**
     * @return array|null [is final, opcode, payload]
     */
    private function takeFrame(): ?array
    {
        if (strlen($this->unread) < 2) {
            return null;
        }

        $first = ord($this->unread[0]);
        $length = ord($this->unread[1]) & 0x7F;
        $offset = 2;
        if ($length === 126) {
            if (strlen($this->unread) < 4) {
                return null;
            }
            $length = unpack('n', $this->unread, 2)[1];
            $offset = 4;
        } elseif ($length === 127) {
            if (strlen($this->unread) < 10) {
                return null;
            }
            $length = unpack('J', $this->unread, 2)[1];
            $offset = 10;
        }

        if (strlen($this->unread) < $offset + $length) {
            return null;
        }

        $payload = substr($this->unread, $offset, $length);
        $this->unread = substr($this->unread, $offset + $length);

        return [($first & 0x80) !== 0, $first & 0x0F, $payload];
    }

    /**
     * Some bytes from the socket, or null when the time is up or it closed
     */
    private function readSome(float $seconds): ?string
    {
        if ($seconds <= 0 || !is_resource($this->stream)) {
            return null;
        }

        $read = [$this->stream];
        $write = $except = null;
        $whole = (int) $seconds;
        if (!@stream_select($read, $write, $except, $whole, (int) (($seconds - $whole) * 1000000))) {
            return null;
        }

        $chunk = fread($this->stream, 65536);
        if ($chunk === '' || $chunk === false) {
            $this->closeCode ??= 1006;
            return null;
        }

        return $chunk;
    }
}

<?php

namespace Tests;

/**
 * For tests that drive the site over real HTTP requests, on PHP's built-in
 * server, like a browser that keeps cookies and doesn't follow redirects
 */
trait DrivesTheSite
{
    /** The built-in server, shared by the tests in the class */
    private static $server;
    private static string $host;

    /** Cookies the "browser" has, by name */
    private array $cookies = [];

    /**
     * Start the site on a free port, with these settings on top of the
     * tests' own, and wait until it takes connections
     *
     * @param array<string, string> $settings Environment variables, by name
     * @param string[] $unset Environment variables the site runs without
     */
    private static function startSite(array $settings = [], array $unset = []): void
    {
        // A free port: bind to 0, read the port back, then let it go
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        self::$host = stream_socket_get_name($socket, false);
        fclose($socket);

        $root = dirname(__DIR__);
        $command = [PHP_BINARY, '-S', self::$host, '-t', "$root/public", "$root/tests/server.php"];
        $environment = $settings + array_diff_key(getenv(), array_flip($unset));
        self::$server = proc_open($command, [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, null, $environment);

        for ($tries = 0; $tries < 50; $tries++) {
            $connection = @fsockopen('tcp://' . self::$host);
            if ($connection) {
                fclose($connection);
                return;
            }
            usleep(100000);
        }
        throw new \RuntimeException('The built-in server did not start on ' . self::$host);
    }

    /**
     * Stop the site
     */
    private static function stopSite(): void
    {
        proc_terminate(self::$server);
        proc_close(self::$server);
    }

    /**
     * Send a request with the cookies set so far. A POST carries this site
     * as its Origin unless told otherwise; a header given as null isn't sent
     *
     * @param array<string, string|int> $form Fields sent as a form
     * @param array<string, ?string> $headers
     *
     * @return array{status: int, headers: array<string, string>, body: string} Header names in lowercase
     */
    private function request(string $method, string $path, array $form = [], array $headers = []): array
    {
        if ($method === 'POST') {
            $headers += ['Origin' => 'http://' . self::$host];
        }
        $headers = array_filter($headers, fn (?string $value): bool => $value !== null);
        if ($form) {
            $headers['Content-Type'] = 'application/x-www-form-urlencoded';
        }
        if ($this->cookies) {
            $headers['Cookie'] = http_build_query($this->cookies, '', '; ');
        }

        $lines = [];
        foreach ($headers as $name => $value) {
            $lines[] = "$name: $value";
        }

        $context = stream_context_create(['http' => [
            'method' => $method,
            'header' => $lines,
            'content' => http_build_query($form),
            'follow_location' => 0,
            'ignore_errors' => true,
        ]]);
        $body = file_get_contents('http://' . self::$host . $path, false, $context);

        $responseHeaders = http_get_last_response_headers();
        preg_match('/^HTTP\/\S+ (\d+)/', array_shift($responseHeaders), $status);

        $found = [];
        foreach ($responseHeaders as $line) {
            [$name, $value] = array_map('trim', explode(':', $line, 2));
            $found[strtolower($name)] = $value;

            if (strtolower($name) === 'set-cookie') {
                $this->keepCookie($value);
            }
        }

        return ['status' => (int) $status[1], 'headers' => $found, 'body' => $body];
    }

    /**
     * Remember a cookie, or forget it when the server deletes it
     */
    private function keepCookie(string $setCookie): void
    {
        [$pair] = explode(';', $setCookie, 2);
        [$name, $value] = explode('=', $pair, 2);

        if ($value === '' || $value === 'deleted') {
            unset($this->cookies[$name]);
        } else {
            $this->cookies[$name] = urldecode($value);
        }
    }

    /**
     * Check the request was sent on to this page
     *
     * @param array{status: int, headers: array<string, string>} $response From request()
     */
    private function assertRedirect(string $location, array $response): void
    {
        $this->assertSame(303, $response['status']);
        $this->assertSame($location, $response['headers']['location'] ?? null);
    }
}

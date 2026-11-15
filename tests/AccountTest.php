<?php

namespace Tests;

use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Logging in, logging out and saving the profile, over real HTTP requests to
 * the app on PHP's built-in server. Changing anything takes a POST from this
 * site; other methods get 405 and change nothing
 */
class AccountTest extends TestCase
{
    const EMAIL = 'account@test.local';
    const PASSWORD = 'secret123';

    /** The built-in server, shared by the tests in this class */
    private static $server;
    private static string $host;

    private PDO $db;

    /** Cookies the "browser" has, by name */
    private array $cookies = [];

    public static function setUpBeforeClass(): void
    {
        // A free port: bind to 0, read the port back, then let it go
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        self::$host = stream_socket_get_name($socket, false);
        fclose($socket);

        $root = dirname(__DIR__);
        $command = [PHP_BINARY, '-S', self::$host, '-t', "$root/public", "$root/tests/server.php"];
        self::$server = proc_open($command, [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);

        // Wait for it to accept connections
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

    public static function tearDownAfterClass(): void
    {
        proc_terminate(self::$server);
        proc_close(self::$server);
    }

    protected function setUp(): void
    {
        // The server is another process, so these rows are committed, not
        // rolled back; tearDown deletes them
        $this->db = TestDb::get();
        $this->db->prepare("INSERT INTO users (name, email, password_hash, is_active) VALUES ('Ann', ?, ?, TRUE)")
            ->execute([self::EMAIL, password_hash(self::PASSWORD, PASSWORD_DEFAULT)]);
    }

    protected function tearDown(): void
    {
        $this->db->prepare('DELETE FROM userinfo WHERE email = ?')->execute([self::EMAIL]);
        $this->db->prepare('DELETE FROM users WHERE email = ?')->execute([self::EMAIL]);
    }

    /**
     * Send a request with the cookies set so far, like a browser that
     * doesn't follow redirects. A POST carries this site as its Origin unless
     * told otherwise
     *
     * @return array ['status' => int, 'headers' => [lowercase name => value], 'body' => string]
     */
    private function request(string $method, string $path, array $form = [], array $headers = []): array
    {
        if ($method === 'POST') {
            $headers += ['Origin' => 'http://' . self::$host];
        }
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

    private function logIn(): void
    {
        $response = $this->request('POST', '/login/create', ['email' => self::EMAIL, 'password' => self::PASSWORD]);

        $this->assertSame(303, $response['status']);
        $this->assertTrue($this->isSignedIn());
    }

    /**
     * The profile page shows only to someone signed in; others go to /login
     */
    private function isSignedIn(): bool
    {
        return $this->request('GET', '/profile/show')['status'] === 200;
    }

    private function user(): array
    {
        $stmt = $this->db->prepare('SELECT name, email, password_hash FROM users WHERE email = ?');
        $stmt->execute([self::EMAIL]);

        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    private function assertRefusedMethod(array $response): void
    {
        $this->assertSame(405, $response['status']);
        $this->assertSame('POST', $response['headers']['allow'] ?? null);
    }

    public function testLoginPageShowsOverGet()
    {
        $this->assertSame(200, $this->request('GET', '/login')['status']);
    }

    public function testLoginOverPostSignsIn()
    {
        $response = $this->request('POST', '/login/create', ['email' => self::EMAIL, 'password' => self::PASSWORD]);

        $this->assertSame(303, $response['status']);
        $this->assertSame('/', $response['headers']['location']);
        $this->assertTrue($this->isSignedIn());
    }

    public function testLoginOverGetIsRefused()
    {
        $query = http_build_query(['email' => self::EMAIL, 'password' => self::PASSWORD]);

        $this->assertRefusedMethod($this->request('GET', "/login/create?$query"));
        $this->assertFalse($this->isSignedIn());
    }

    public function testLogoutOverPostSignsOutWithAMessage()
    {
        $this->logIn();

        $response = $this->request('POST', '/logout');
        $this->assertSame(303, $response['status']);
        $this->assertSame('/login/show-logout-message', $response['headers']['location']);

        $response = $this->request('GET', '/login/show-logout-message');
        $this->assertSame('/login', $response['headers']['location']);
        $this->assertStringContainsString('Logged out', $this->request('GET', '/login')['body']);

        $this->assertFalse($this->isSignedIn());
    }

    public function testLogoutOverGetLeavesYouSignedIn()
    {
        $this->logIn();

        $this->assertRefusedMethod($this->request('GET', '/logout'));
        $this->assertTrue($this->isSignedIn());
    }

    public function testLogoutFromAnotherSiteIsRefused()
    {
        $this->logIn();

        $response = $this->request('POST', '/logout', [], ['Origin' => 'https://elsewhere.example']);

        $this->assertSame(403, $response['status']);
        $this->assertTrue($this->isSignedIn());
    }

    public function testProfileUpdateOverPostSaves()
    {
        $this->logIn();

        $response = $this->request('POST', '/profile/update', ['name' => 'Ann Lee', 'email' => self::EMAIL]);

        $this->assertSame(303, $response['status']);
        $this->assertSame('/profile/show', $response['headers']['location']);
        $this->assertSame('Ann Lee', $this->user()['name']);
    }

    public function testProfileUpdateWithoutPostChangesNothing()
    {
        $this->logIn();
        $before = $this->user();
        $form = ['name' => 'Ann Lee', 'email' => 'other@test.local', 'password' => 'newpass123', 'current_password' => self::PASSWORD];

        $this->assertRefusedMethod($this->request('GET', '/profile/update?' . http_build_query($form)));
        $this->assertRefusedMethod($this->request('PUT', '/profile/update', $form));

        $this->assertSame($before, $this->user());
        $this->assertTrue($this->isSignedIn());
    }
}

<?php

namespace Tests;

use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Logging in, logging out and saving the profile, over real HTTP requests to
 * the app on PHP's built-in server. Changing anything takes a POST from this
 * site; other methods get 405 and change nothing. Also the route table: only
 * listed routes answer, each with its own method
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
        // A site without Cloudinary settings, so photo uploads are off
        $environment = array_diff_key(getenv(), ['CLOUDINARY_CLOUD_NAME' => '', 'CLOUDINARY_UPLOAD_PRESET' => '']);
        self::$server = proc_open($command, [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, null, $environment);

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
     * told otherwise; a header given as null isn't sent
     *
     * @return array ['status' => int, 'headers' => [lowercase name => value], 'body' => string]
     */
    private function request(string $method, string $path, array $form = [], array $headers = []): array
    {
        if ($method === 'POST') {
            $headers += ['Origin' => 'http://' . self::$host];
        }
        $headers = array_filter($headers, fn ($value) => $value !== null);
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
     * Log in as the test's user, as the login form does
     */
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

    /**
     * The test user's row as saved
     */
    private function user(): array
    {
        $stmt = $this->db->prepare('SELECT name, email, password_hash FROM users WHERE email = ?');
        $stmt->execute([self::EMAIL]);

        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    /**
     * The test user's saved photo address, or null for the drawn avatar
     */
    private function avatar(): ?string
    {
        $stmt = $this->db->prepare('SELECT avatar FROM users WHERE email = ?');
        $stmt->execute([self::EMAIL]);

        return $stmt->fetchColumn();
    }

    /**
     * Check the request was refused for its method, with the methods the URL takes
     */
    private function assertRefusedMethod(array $response, string $allow = 'POST'): void
    {
        $this->assertSame(405, $response['status']);
        $this->assertSame($allow, $response['headers']['allow'] ?? null);
    }

    /**
     * Check the request was sent on to this page
     */
    private function assertRedirect(string $location, array $response): void
    {
        $this->assertSame(303, $response['status']);
        $this->assertSame($location, $response['headers']['location'] ?? null);
    }

    public function testLoginPageShowsOverGet()
    {
        $this->assertSame(200, $this->request('GET', '/login')['status']);
    }

    public function testEveryScriptOnAPageCarriesThePolicysNonce()
    {
        $this->logIn();

        foreach (['/login', '/app', '/profile/show', '/about'] as $path) {
            $response = $this->request('GET', $path);
            $this->assertMatchesRegularExpression("/script-src 'nonce-([^']+)' 'strict-dynamic'/", $response['headers']['content-security-policy']);
            preg_match("/'nonce-([^']+)'/", $response['headers']['content-security-policy'], $nonce);

            preg_match_all('/<script\b[^>]*>/', $response['body'], $scripts);
            $this->assertNotEmpty($scripts[0], $path);
            foreach ($scripts[0] as $script) {
                $this->assertStringContainsString('nonce="' . $nonce[1] . '"', $script, "$path: $script");
            }
        }
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

    public function testWithoutCloudinaryTheProfileSaysPhotoUploadsAreOff()
    {
        $this->logIn();

        $page = $this->request('GET', '/profile/show')['body'];

        $this->assertStringContainsString("Photo uploads aren't set up on this site", $page);
        $this->assertStringNotContainsString('Change photo', $page);
        $this->assertStringNotContainsString('id="file-upload"', $page);
    }

    public function testWithoutCloudinaryAPhotoIsRefusedButOneCanStillBeRemoved()
    {
        $this->logIn();
        $photo = 'https://res.cloudinary.com/someone-else/image/upload/v1/ann.jpg';

        $this->assertSame(400, $this->request('POST', '/profile/update-avatar', ['avatar' => $photo])['status']);
        $this->assertNull($this->avatar());

        $this->db->prepare('UPDATE users SET avatar = ? WHERE email = ?')->execute([$photo, self::EMAIL]);
        $this->assertSame(200, $this->request('POST', '/profile/remove-avatar')['status']);
        $this->assertNull($this->avatar());
    }

    public function testPagesAndTheirOlderUrls()
    {
        $this->assertRedirect('/login', $this->request('GET', '/'));
        $this->assertRedirect('/login', $this->request('GET', '/home/index'));
        $this->assertSame(200, $this->request('GET', '/about')['status']);
        $this->assertSame(200, $this->request('GET', '/about/index')['status']);
        $this->assertSame(200, $this->request('GET', '/login/new')['status']);
        $this->assertSame(200, $this->request('GET', '/signup/new')['status']);
        $this->assertSame(200, $this->request('GET', '/password/forgot')['status']);

        $this->logIn();
        $this->assertRedirect('/app', $this->request('GET', '/'));
        $this->assertSame(200, $this->request('GET', '/app')['status']);
        $this->assertSame(200, $this->request('GET', '/app/index')['status']);
    }

    public function testHeadAnswersLikeGet()
    {
        $this->assertSame(200, $this->request('HEAD', '/login')['status']);
        $this->assertRedirect('/login', $this->request('HEAD', '/'));
        $this->assertRefusedMethod($this->request('HEAD', '/logout'));
    }

    public function testOlderLogoutUrlSignsOut()
    {
        $this->logIn();

        $this->assertRefusedMethod($this->request('GET', '/login/destroy'));
        $this->assertTrue($this->isSignedIn());

        $this->assertRedirect('/login/show-logout-message', $this->request('POST', '/login/destroy'));
        $this->assertFalse($this->isSignedIn());
    }

    public function testPostOnlyUrlsRefuseGet()
    {
        foreach (['/login/guest', '/signup/create', '/password/request-reset', '/password/reset-password', '/app/get', '/app/send', '/app/get-locations', '/profile/update-avatar'] as $path) {
            $this->assertRefusedMethod($this->request('GET', $path), 'POST');
        }
    }

    public function testGetOnlyUrlsRefusePost()
    {
        $this->assertRefusedMethod($this->request('POST', '/login'), 'GET, HEAD');
        $this->assertRefusedMethod($this->request('POST', '/profile/show'), 'GET, HEAD');
        $this->assertRefusedMethod($this->request('DELETE', '/about'), 'GET, HEAD');
    }

    public function testAPostWithNeitherOriginNorRefererIsRefused()
    {
        $this->logIn();

        $response = $this->request('POST', '/logout', [], ['Origin' => null]);

        $this->assertSame(403, $response['status']);
        $this->assertTrue($this->isSignedIn());
    }

    public function testAPostFromThisHostOnAnotherPortIsRefused()
    {
        $this->logIn();
        [$host, $port] = explode(':', self::$host);

        $response = $this->request('POST', '/logout', [], ['Origin' => "http://$host:" . ($port + 1)]);

        $this->assertSame(403, $response['status']);
        $this->assertTrue($this->isSignedIn());
    }

    public function testAPostWithOnlyAThisSiteRefererIsLetIn()
    {
        $this->logIn();

        $response = $this->request('POST', '/logout', [], ['Origin' => null, 'Referer' => 'http://' . self::$host . '/app']);

        $this->assertSame(303, $response['status']);
        $this->assertFalse($this->isSignedIn());
    }

    public function testLoggingOutStopsPushOnThatBrowserOnly()
    {
        $thisBrowser = 'https://push.example.com/this-browser';
        $otherBrowser = 'https://push.example.com/other-browser';
        foreach ([$thisBrowser, $otherBrowser] as $endpoint) {
            $this->db->prepare("INSERT INTO push_subscriptions (email, endpoint, endpoint_hash, p256dh, auth) VALUES (?, ?, ?, 'key', 'secret')")
                ->execute([self::EMAIL, $endpoint, hash('sha256', $endpoint)]);
        }
        $this->logIn();

        $this->assertRedirect('/login/show-logout-message', $this->request('POST', '/logout', ['push_endpoint' => $thisBrowser]));

        $stmt = $this->db->prepare('SELECT endpoint FROM push_subscriptions WHERE email = ?');
        $stmt->execute([self::EMAIL]);
        $this->assertSame([$otherBrowser], $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    public function testAResetRequestWithEmailOffSaysSoWhateverTheEmail()
    {
        foreach ([self::EMAIL, 'nobody@test.local'] as $email) {
            $response = $this->request('POST', '/password/request-reset', ['email' => $email]);
            $this->assertRedirect('/password/forgot', $response);

            $page = $this->request('GET', '/password/forgot')['body'];
            $this->assertStringContainsString('passwords can&#039;t be reset here', $page, $email);
            $this->assertStringNotContainsString('Check your email', $page, $email);
        }
    }

    public function testUnknownUrlIs404()
    {
        $this->assertSame(404, $this->request('GET', '/nothing/here')['status']);
        $this->assertSame(404, $this->request('POST', '/nothing/here')['status']);
    }

    public function testControllerMethodsWithoutARouteAreUnreachable()
    {
        $this->logIn();

        // Methods the old {controller}/{action} route would have tried
        $paths = ['/app/refresh-location', '/app/catch-up-demo-chat', '/app/refuse', '/profile/refuse', '/profile/before',
            '/password/get-user-or-exit', '/login/run-action', '/login/new-action', '/home/index-action', '/profile/show-action'];
        foreach ($paths as $path) {
            $this->assertSame(404, $this->request('GET', $path)['status'], "GET $path");
            $this->assertSame(404, $this->request('POST', $path)['status'], "POST $path");
        }
    }

    public function testRouteVariableOutsideItsPatternIs404()
    {
        $this->logIn();

        $this->assertSame(404, $this->request('GET', '/avatar/abc')['status']);
        $this->assertSame(404, $this->request('GET', '/signup/activate/not-hex')['status']);
        $this->assertSame(404, $this->request('GET', '/password/reset/zz')['status']);
    }

    public function testAvatarRouteSendsTheImage()
    {
        $this->logIn();
        $id = $this->db->query("SELECT id FROM users WHERE email = '" . self::EMAIL . "'")->fetchColumn();

        $response = $this->request('GET', "/avatar/$id?v=2");

        $this->assertSame(200, $response['status']);
        $this->assertSame('image/svg+xml', $response['headers']['content-type']);
    }

    public function testActivationLinkFromTheEmailActivates()
    {
        $token = bin2hex(random_bytes(16));
        $this->db->prepare('UPDATE users SET is_active = FALSE, activation_hash = ? WHERE email = ?')
            ->execute([(new \App\Token($token))->getHash(), self::EMAIL]);

        $this->assertRedirect('/signup/activated', $this->request('GET', "/signup/activate/$token"));
        $this->assertRedirect('/login', $this->request('GET', '/signup/activated'));
        $this->assertStringContainsString('Your account is active', $this->request('GET', '/login')['body']);

        $this->assertSame(1, (int) $this->db->query("SELECT is_active FROM users WHERE email = '" . self::EMAIL . "'")->fetchColumn());
    }

    public function testResetLinkFromTheEmailShowsTheForm()
    {
        $token = bin2hex(random_bytes(16));
        $this->db->prepare('UPDATE users SET password_reset_hash = ?, password_reset_expires_at = DATE_ADD(NOW(), INTERVAL 1 HOUR) WHERE email = ?')
            ->execute([(new \App\Token($token))->getHash(), self::EMAIL]);

        $response = $this->request('GET', "/password/reset/$token");
        $this->assertSame(200, $response['status']);
        $this->assertStringContainsString($token, $response['body']);

        $response = $this->request('POST', '/password/reset-password', ['token' => $token, 'password' => 'newpass123']);
        $this->assertRedirect('/login/new', $response);
        $this->assertTrue(password_verify('newpass123', $this->user()['password_hash']));
    }

    public function testEmailCheckReadsTheQueryString()
    {
        $taken = $this->request('GET', '/account/validate-email?' . http_build_query(['email' => self::EMAIL]));
        $free = $this->request('GET', '/account/validate-email?' . http_build_query(['email' => 'free@test.local']));

        $this->assertSame('false', $taken['body']);
        $this->assertSame('true', $free['body']);
    }

    public function testProtectedPageSendsYouToLogInAndBack()
    {
        $this->assertRedirect('/login', $this->request('GET', '/profile/show?tab=1'));

        $response = $this->request('POST', '/login/create', ['email' => self::EMAIL, 'password' => self::PASSWORD]);
        $this->assertRedirect('/profile/show?tab=1', $response);
    }

    public function testProtectedAjaxRequestAsksYouToLogInAgain()
    {
        $headers = ['X-Requested-With' => 'XMLHttpRequest'];

        $response = $this->request('POST', '/app/get-people', [], $headers);
        $this->assertSame(401, $response['status']);
        $this->assertSame(['error' => 'Please log in again.'], json_decode($response['body'], true));

        // The login check stops the action before it saves anything
        $this->db->prepare('UPDATE users SET share_location = FALSE WHERE email = ?')->execute([self::EMAIL]);
        $response = $this->request('POST', '/profile/update-location-sharing', ['share' => 1], $headers);
        $this->assertSame(401, $response['status']);
        $this->assertSame(0, (int) $this->db->query("SELECT share_location FROM users WHERE email = '" . self::EMAIL . "'")->fetchColumn());

        $this->logIn();
        $response = $this->request('POST', '/app/get-people', [], $headers);
        $this->assertSame(200, $response['status']);
        $this->assertIsArray(json_decode($response['body'], true));
    }
}

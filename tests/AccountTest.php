<?php

namespace Tests;

use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Logging in, logging out and saving the profile, over real HTTP requests to
 * the app on PHP's built-in server. Changing anything takes a POST from this
 * site; other methods get 405 and change nothing. Also the site's own
 * routes: a controller method without one can't be reached, and ids and
 * tokens keep to their patterns (how the router matches is RouterTest's)
 */
class AccountTest extends TestCase
{
    use DrivesTheSite;

    const EMAIL = 'account@test.local';
    const PASSWORD = 'secret123';
    /** Someone else, for a private message to the test's user */
    const OTHER_EMAIL = 'other@test.local';
    /** The email a guest keeps their account with, or someone signs up with */
    const NEW_EMAIL = 'new@test.local';

    private PDO $db;

    public static function setUpBeforeClass(): void
    {
        // A site without Cloudinary settings, so photo uploads are off, and
        // with a Google client, so the login page offers it
        self::startSite(
            ['GOOGLE_CLIENT_ID' => 'mapchat-test.apps.googleusercontent.com', 'GOOGLE_CLIENT_SECRET' => 'test-secret'],
            unset: ['CLOUDINARY_CLOUD_NAME', 'CLOUDINARY_UPLOAD_PRESET']
        );
    }

    public static function tearDownAfterClass(): void
    {
        self::stopSite();
    }

    protected function setUp(): void
    {
        // The server is another process, so these rows are committed, not
        // rolled back; tearDown deletes them
        $this->db = TestDb::get();
        TestDb::createUser('Ann', self::EMAIL, self::PASSWORD);
    }

    protected function tearDown(): void
    {
        $this->db->prepare('DELETE FROM userinfo WHERE email = ?')->execute([self::EMAIL]);
        $this->db->prepare('DELETE FROM users WHERE email IN (?, ?, ?)')->execute([self::EMAIL, self::OTHER_EMAIL, self::NEW_EMAIL]);
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

    public function testLoginPageShowsOverGet(): void
    {
        $this->assertSame(200, $this->request('GET', '/login')['status']);
    }

    public function testEveryScriptOnAPageCarriesThePolicysNonce(): void
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

    public function testLoginOverPostSignsIn(): void
    {
        $response = $this->request('POST', '/login/create', ['email' => self::EMAIL, 'password' => self::PASSWORD]);

        $this->assertSame(303, $response['status']);
        $this->assertSame('/', $response['headers']['location']);
        $this->assertTrue($this->isSignedIn());
    }

    public function testLoginOverGetIsRefused(): void
    {
        $query = http_build_query(['email' => self::EMAIL, 'password' => self::PASSWORD]);

        $this->assertRefusedMethod($this->request('GET', "/login/create?$query"));
        $this->assertFalse($this->isSignedIn());
    }

    public function testLogoutOverPostSignsOutWithAMessage(): void
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

    public function testLogoutOverGetLeavesYouSignedIn(): void
    {
        $this->logIn();

        $this->assertRefusedMethod($this->request('GET', '/logout'));
        $this->assertTrue($this->isSignedIn());
    }

    public function testLogoutFromAnotherSiteIsRefused(): void
    {
        $this->logIn();

        $response = $this->request('POST', '/logout', [], ['Origin' => 'https://elsewhere.example']);

        $this->assertSame(403, $response['status']);
        $this->assertTrue($this->isSignedIn());
    }

    public function testProfileUpdateOverPostSaves(): void
    {
        $this->logIn();

        $response = $this->request('POST', '/profile/update', ['name' => 'Ann Lee', 'email' => self::EMAIL]);

        $this->assertSame(303, $response['status']);
        $this->assertSame('/profile/show', $response['headers']['location']);
        $this->assertSame('Ann Lee', $this->user()['name']);
    }

    public function testProfileUpdateWithoutPostChangesNothing(): void
    {
        $this->logIn();
        $before = $this->user();
        $form = ['name' => 'Ann Lee', 'email' => 'other@test.local', 'password' => 'newpass123', 'current_password' => self::PASSWORD];

        $this->assertRefusedMethod($this->request('GET', '/profile/update?' . http_build_query($form)));
        $this->assertRefusedMethod($this->request('PUT', '/profile/update', $form));

        $this->assertSame($before, $this->user());
        $this->assertTrue($this->isSignedIn());
    }

    public function testWithoutCloudinaryTheProfileSaysPhotoUploadsAreOff(): void
    {
        $this->logIn();

        $page = $this->request('GET', '/profile/show')['body'];

        $this->assertStringContainsString("Photo uploads aren't set up on this site", $page);
        $this->assertStringNotContainsString('Change photo', $page);
        $this->assertStringNotContainsString('id="file-upload"', $page);
    }

    public function testWithoutCloudinaryAPhotoIsRefusedButOneCanStillBeRemoved(): void
    {
        $this->logIn();
        $photo = 'https://res.cloudinary.com/someone-else/image/upload/v1/ann.jpg';

        $this->assertSame(400, $this->request('POST', '/profile/update-avatar', ['avatar' => $photo])['status']);
        $this->assertNull($this->avatar());

        $this->db->prepare('UPDATE users SET avatar = ? WHERE email = ?')->execute([$photo, self::EMAIL]);
        $this->assertSame(200, $this->request('POST', '/profile/remove-avatar')['status']);
        $this->assertNull($this->avatar());
    }

    public function testPagesAndTheirOlderUrls(): void
    {
        $this->assertRedirect('/login', $this->request('GET', '/'));
        $this->assertRedirect('/login', $this->request('GET', '/home/index'));
        $this->assertSame(200, $this->request('GET', '/about')['status']);
        $this->assertSame(200, $this->request('GET', '/privacy')['status']);
        $this->assertSame(200, $this->request('GET', '/about/index')['status']);
        $this->assertSame(200, $this->request('GET', '/login/new')['status']);
        $this->assertSame(200, $this->request('GET', '/signup/new')['status']);
        $this->assertSame(200, $this->request('GET', '/password/forgot')['status']);

        $this->logIn();
        $this->assertRedirect('/app', $this->request('GET', '/'));
        $this->assertSame(200, $this->request('GET', '/app')['status']);
        $this->assertSame(200, $this->request('GET', '/app/index')['status']);
    }

    public function testHeadAnswersLikeGet(): void
    {
        $this->assertSame(200, $this->request('HEAD', '/login')['status']);
        $this->assertRedirect('/login', $this->request('HEAD', '/'));
        $this->assertRefusedMethod($this->request('HEAD', '/logout'));
    }

    public function testOlderLogoutUrlSignsOut(): void
    {
        $this->logIn();

        $this->assertRefusedMethod($this->request('GET', '/login/destroy'));
        $this->assertTrue($this->isSignedIn());

        $this->assertRedirect('/login/show-logout-message', $this->request('POST', '/login/destroy'));
        $this->assertFalse($this->isSignedIn());
    }

    public function testPostOnlyUrlsRefuseGet(): void
    {
        foreach (['/login/guest', '/signup/create', '/password/request-reset', '/password/reset-password', '/app/get', '/app/send', '/app/get-locations', '/profile/update-avatar', '/profile/delete'] as $path) {
            $this->assertRefusedMethod($this->request('GET', $path), 'POST');
        }
    }

    public function testGetOnlyUrlsRefusePost(): void
    {
        $this->assertRefusedMethod($this->request('POST', '/login'), 'GET, HEAD');
        $this->assertRefusedMethod($this->request('POST', '/profile/show'), 'GET, HEAD');
        $this->assertRefusedMethod($this->request('DELETE', '/about'), 'GET, HEAD');
    }

    public function testAPostWithNeitherOriginNorRefererIsRefused(): void
    {
        $this->logIn();

        $response = $this->request('POST', '/logout', [], ['Origin' => null]);

        $this->assertSame(403, $response['status']);
        $this->assertTrue($this->isSignedIn());
    }

    public function testAPostFromThisHostOnAnotherPortIsRefused(): void
    {
        $this->logIn();
        [$host, $port] = explode(':', self::$host);

        $response = $this->request('POST', '/logout', [], ['Origin' => "http://$host:" . ($port + 1)]);

        $this->assertSame(403, $response['status']);
        $this->assertTrue($this->isSignedIn());
    }

    public function testAPostWithOnlyAThisSiteRefererIsLetIn(): void
    {
        $this->logIn();

        $response = $this->request('POST', '/logout', [], ['Origin' => null, 'Referer' => 'http://' . self::$host . '/app']);

        $this->assertSame(303, $response['status']);
        $this->assertFalse($this->isSignedIn());
    }

    public function testLoggingOutStopsPushOnThatBrowserOnly(): void
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

    /**
     * How many rows of a table belong to the test's user
     *
     * @param string $column The column holding their email, or their id for user_id
     */
    private function countOwnRows(string $table, string $column = 'email'): int
    {
        $owner = $column === 'user_id' ? '(SELECT id FROM users WHERE email = ?)' : '?';
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM $table WHERE $column = $owner");
        $stmt->execute([self::EMAIL]);

        return (int) $stmt->fetchColumn();
    }

    public function testTheProfilePageOffersToDeleteTheProfileWithThePassword(): void
    {
        $this->logIn();

        $page = $this->request('GET', '/profile/show')['body'];

        $this->assertStringContainsString('<dialog class="delete-dialog" id="delete-profile-dialog"', $page);
        $this->assertStringContainsString('action="/profile/delete" method="post"', $page);
        $this->assertStringContainsString('id="delete-password-input" name="current_password"', $page);
    }

    public function testDeletingTheProfileDeletesEverythingOfTheirsAndSignsOut(): void
    {
        TestDb::createUser('Bob', self::OTHER_EMAIL);
        $this->db->prepare("INSERT INTO chats (email, to_email, body) VALUES (?, NULL, 'Hello room'), (?, ?, 'Hi Ann')")
            ->execute([self::EMAIL, self::OTHER_EMAIL, self::EMAIL]);
        $this->db->prepare("INSERT INTO userinfo (email, ip, city) VALUES (?, '127.0.0.1', 'Dhaka')")->execute([self::EMAIL]);
        $this->db->prepare("INSERT INTO push_subscriptions (email, endpoint, endpoint_hash, p256dh, auth) VALUES (?, 'https://push.example.com/ann', ?, 'key', 'secret')")
            ->execute([self::EMAIL, hash('sha256', 'https://push.example.com/ann')]);
        $userId = (int) $this->db->query("SELECT id FROM users WHERE email = '" . self::EMAIL . "'")->fetchColumn();
        $this->request('POST', '/login/create', ['email' => self::EMAIL, 'password' => self::PASSWORD, 'remember_me' => '1']);
        $this->assertSame(1, $this->countOwnRows('remembered_logins', 'user_id'));

        $response = $this->request('POST', '/profile/delete', ['current_password' => self::PASSWORD]);

        $this->assertRedirect('/login/show-deleted-message', $response);
        $this->assertRedirect('/', $this->request('GET', '/login/show-deleted-message'));
        $this->assertStringContainsString('Your profile is deleted.', $this->request('GET', '/login')['body']);
        $this->assertFalse($this->isSignedIn());

        $this->assertSame(0, $this->countOwnRows('users'));
        $this->assertSame(0, $this->countOwnRows('chats'));
        $this->assertSame(0, $this->countOwnRows('chats', 'to_email'));
        $this->assertSame(0, $this->countOwnRows('userinfo'));
        $this->assertSame(0, $this->countOwnRows('push_subscriptions'));
        $stmt = $this->db->prepare('SELECT COUNT(*) FROM remembered_logins WHERE user_id = ?');
        $stmt->execute([$userId]);
        $this->assertSame(0, (int) $stmt->fetchColumn());
    }

    public function testDeletingTheProfileWithAWrongPasswordKeepsIt(): void
    {
        $this->logIn();

        $this->assertRedirect('/profile/show', $this->request('POST', '/profile/delete', ['current_password' => 'wrong123']));

        $this->assertSame(1, $this->countOwnRows('users'));
        $this->assertTrue($this->isSignedIn());
    }

    public function testDeletingTheProfileFromAnotherSiteIsRefused(): void
    {
        $this->logIn();

        $response = $this->request('POST', '/profile/delete', ['current_password' => self::PASSWORD], ['Origin' => 'https://elsewhere.example']);

        $this->assertSame(403, $response['status']);
        $this->assertSame(1, $this->countOwnRows('users'));
    }

    public function testAGuestDeletesTheirProfileWithoutAPassword(): void
    {
        $this->request('POST', '/login/guest');
        $this->assertTrue($this->isSignedIn());
        $this->assertStringNotContainsString('id="delete-password-input"', $this->request('GET', '/profile/show')['body']);

        $this->assertRedirect('/login/show-deleted-message', $this->request('POST', '/profile/delete'));
        $this->assertFalse($this->isSignedIn());
    }

    public function testAResetRequestWithEmailOffSaysSoWhateverTheEmail(): void
    {
        foreach ([self::EMAIL, 'nobody@test.local'] as $email) {
            $response = $this->request('POST', '/password/request-reset', ['email' => $email]);
            $this->assertRedirect('/password/forgot', $response);

            $page = $this->request('GET', '/password/forgot')['body'];
            $this->assertStringContainsString('passwords can&#039;t be reset here', $page, $email);
            $this->assertStringNotContainsString('Check your email', $page, $email);
        }
    }

    public function testControllerMethodsWithoutARouteAreUnreachable(): void
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

    public function testTheSitesIdAndTokenRoutesAre404ForAnythingElse(): void
    {
        $this->logIn();

        $this->assertSame(404, $this->request('GET', '/avatar/abc')['status']);
        $this->assertSame(404, $this->request('GET', '/signup/activate/not-hex')['status']);
        $this->assertSame(404, $this->request('GET', '/password/reset/zz')['status']);
    }

    public function testAvatarRouteSendsTheImage(): void
    {
        $this->logIn();
        $id = $this->db->query("SELECT id FROM users WHERE email = '" . self::EMAIL . "'")->fetchColumn();

        $response = $this->request('GET', "/avatar/$id?v=2");

        $this->assertSame(200, $response['status']);
        $this->assertSame('image/svg+xml', $response['headers']['content-type']);
    }

    public function testActivationLinkFromTheEmailActivates(): void
    {
        $token = bin2hex(random_bytes(16));
        $this->db->prepare('UPDATE users SET is_active = FALSE, activation_hash = ? WHERE email = ?')
            ->execute([(new \App\SignIn\Token($token))->getHash(), self::EMAIL]);

        $this->assertRedirect('/signup/activated', $this->request('GET', "/signup/activate/$token"));
        $this->assertRedirect('/login', $this->request('GET', '/signup/activated'));
        $this->assertStringContainsString('Your account is active', $this->request('GET', '/login')['body']);

        $this->assertSame(1, (int) $this->db->query("SELECT is_active FROM users WHERE email = '" . self::EMAIL . "'")->fetchColumn());
    }

    public function testResetLinkFromTheEmailShowsTheFormThatSetsTheNewPassword(): void
    {
        $token = bin2hex(random_bytes(16));
        $this->db->prepare('UPDATE users SET password_reset_hash = ?, password_reset_expires_at = DATE_ADD(NOW(), INTERVAL 1 HOUR) WHERE email = ?')
            ->execute([(new \App\SignIn\Token($token))->getHash(), self::EMAIL]);

        $response = $this->request('GET', "/password/reset/$token");
        $this->assertSame(200, $response['status']);
        $this->assertStringContainsString($token, $response['body']);

        $response = $this->request('POST', '/password/reset-password', ['token' => $token, 'password' => 'newpass123']);
        $this->assertRedirect('/login/new', $response);
        $this->assertTrue(password_verify('newpass123', $this->user()['password_hash']));
    }

    public function testEmailCheckReadsTheQueryString(): void
    {
        $taken = $this->request('GET', '/account/validate-email?' . http_build_query(['email' => self::EMAIL]));
        $free = $this->request('GET', '/account/validate-email?' . http_build_query(['email' => 'free@test.local']));

        $this->assertSame('false', $taken['body']);
        $this->assertSame('true', $free['body']);
    }

    public function testProtectedPageSendsYouToLogInAndBack(): void
    {
        $this->assertRedirect('/login', $this->request('GET', '/profile/show?tab=1'));

        $response = $this->request('POST', '/login/create', ['email' => self::EMAIL, 'password' => self::PASSWORD]);
        $this->assertRedirect('/profile/show?tab=1', $response);
    }

    public function testProtectedAjaxRequestAsksYouToLogInAgain(): void
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

    public function testTheLoginPageOffersGoogle(): void
    {
        $this->assertStringContainsString('href="/login/google"', $this->request('GET', '/login')['body']);
    }

    public function testContinueWithGoogleSendsYouToGoogleWithAStateAndAChallenge(): void
    {
        $response = $this->request('GET', '/login/google');

        $this->assertSame(303, $response['status']);
        $location = $response['headers']['location'];
        $this->assertStringStartsWith('https://accounts.google.com/o/oauth2/v2/auth?', $location);

        parse_str(parse_url($location, PHP_URL_QUERY), $query);
        $this->assertSame('mapchat-test.apps.googleusercontent.com', $query['client_id']);
        $this->assertStringEndsWith('/login/google/callback', $query['redirect_uri']);
        $this->assertSame('openid email profile', $query['scope']);
        $this->assertSame('S256', $query['code_challenge_method']);
        $this->assertNotEmpty($query['state']);
        $this->assertNotEmpty($query['nonce']);
        $this->assertNotEmpty($query['code_challenge']);
    }

    public function testAGoogleCallbackThisBrowserDidNotStartIsRefused(): void
    {
        $this->request('GET', '/login/google');

        $response = $this->request('GET', '/login/google/callback?state=someone-elses&code=abc');

        $this->assertRedirect('/login', $response);
        $this->assertFalse($this->isSignedIn());
    }

    public function testCancellingAtGoogleComesBackToTheLoginPage(): void
    {
        $response = $this->request('GET', '/login/google/callback?error=access_denied');

        $this->assertRedirect('/login', $response);
        $this->assertStringContainsString('Google sign-in was cancelled.', $this->request('GET', '/login')['body']);
    }

    public function testAnAccountWithNoPasswordIsToldToUseGoogle(): void
    {
        $this->db->prepare("UPDATE users SET password_hash = '' WHERE email = ?")->execute([self::EMAIL]);

        $response = $this->request('POST', '/login/create', ['email' => self::EMAIL, 'password' => '']);

        $this->assertSame(200, $response['status']);
        $this->assertStringContainsString('This account signs in with Google.', $response['body']);
        $this->assertFalse($this->isSignedIn());
    }

    public function testAnAccountWithNoPasswordSetsOneWithoutACurrentOne(): void
    {
        $this->logIn();
        $this->db->prepare("UPDATE users SET password_hash = '' WHERE email = ?")->execute([self::EMAIL]);

        $response = $this->request('POST', '/profile/update', ['name' => 'Ann', 'email' => self::EMAIL, 'password' => 'newpass1']);

        $this->assertRedirect('/profile/show', $response);
        $this->assertTrue(password_verify('newpass1', $this->user()['password_hash']));
    }

    public function testLoggingInWithAWrongPasswordSaysSoAndLeavesYouOut(): void
    {
        $response = $this->request('POST', '/login/create', ['email' => self::EMAIL, 'password' => 'wrong123']);

        $this->assertSame(200, $response['status']);
        $this->assertStringContainsString('Wrong email or password.', $response['body']);
        $this->assertStringContainsString('value="' . self::EMAIL . '"', $response['body']);
        $this->assertFalse($this->isSignedIn());
    }

    public function testAnAccountNotYetActivatedIsAskedToActivateFirst(): void
    {
        $this->db->prepare('UPDATE users SET is_active = FALSE WHERE email = ?')->execute([self::EMAIL]);

        $response = $this->request('POST', '/login/create', ['email' => self::EMAIL, 'password' => self::PASSWORD]);

        $this->assertSame(200, $response['status']);
        $this->assertStringContainsString('Activate your account first.', $response['body']);
        $this->assertFalse($this->isSignedIn());
    }

    public function testWithoutEmailSigningUpMakesAnActiveAccount(): void
    {
        $response = $this->request('POST', '/signup/create', ['name' => 'Nia Roe', 'email' => self::NEW_EMAIL, 'password' => 'secret123']);

        $this->assertRedirect('/signup/activated', $response);
        $stmt = $this->db->prepare('SELECT name, is_active FROM users WHERE email = ?');
        $stmt->execute([self::NEW_EMAIL]);
        $account = $stmt->fetch(PDO::FETCH_ASSOC);
        $this->assertSame('Nia Roe', $account['name']);
        $this->assertSame(1, (int) $account['is_active']);
    }

    public function testSigningUpWithAnEmailThatHasAnAccountIsRefusedUnderTheField(): void
    {
        $response = $this->request('POST', '/signup/create', ['name' => 'Ann Two', 'email' => self::EMAIL, 'password' => 'secret123']);

        $this->assertSame(200, $response['status']);
        $this->assertMatchesRegularExpression('/id="email-input-error">[^<]+</', $response['body']);
        $this->assertSame(1, $this->countOwnRows('users'));
    }

    public function testAGuestKeepsTheirAccountWithAnEmailAndPassword(): void
    {
        $this->request('POST', '/login/guest');
        $this->assertStringContainsString('Keep my account', $this->request('GET', '/profile/show')['body']);

        $response = $this->request('POST', '/profile/update', ['name' => 'Kim Lee', 'email' => self::NEW_EMAIL, 'password' => 'newpass123']);

        $this->assertRedirect('/profile/show', $response);
        $stmt = $this->db->prepare('SELECT name, is_guest, password_hash FROM users WHERE email = ?');
        $stmt->execute([self::NEW_EMAIL]);
        $kept = $stmt->fetch(PDO::FETCH_ASSOC);
        $this->assertSame('Kim Lee', $kept['name']);
        $this->assertSame(0, (int) $kept['is_guest']);
        $this->assertTrue(password_verify('newpass123', $kept['password_hash']));
        $this->assertStringNotContainsString('Keep my account', $this->request('GET', '/profile/show')['body']);
    }

    public function testAGuestCantKeepTheirAccountWithoutAnEmail(): void
    {
        $this->request('POST', '/login/guest');

        $this->assertRedirect('/profile/show', $this->request('POST', '/profile/update', ['name' => 'Kim Lee', 'email' => '', 'password' => 'newpass123']));

        // The warning goes to the page as a toast, in a data attribute
        $page = $this->request('GET', '/profile/show')['body'];
        $this->assertStringContainsString('"Enter your email to keep your account."', html_entity_decode($page, ENT_QUOTES | ENT_HTML5));
        $this->assertStringContainsString('Keep my account', $page);

        $this->request('POST', '/profile/delete');
    }

    public function testTurningLocationSharingOffTakesYourPinOffTheMapAndOnPutsItBack(): void
    {
        $this->logIn();
        $this->db->prepare("UPDATE userinfo SET lat = 23.8, lng = 90.4, city = 'Dhaka', country = 'BD', online_at = NOW() WHERE email = ?")
            ->execute([self::EMAIL]);
        $userId = (int) $this->db->query("SELECT id FROM users WHERE email = '" . self::EMAIL . "'")->fetchColumn();

        $this->assertSame(['share_location' => false], json_decode($this->request('POST', '/profile/update-location-sharing', ['share' => ''])['body'], true));
        $this->assertNotContains($userId, array_column($this->locations(), 'user_id'));

        $this->assertSame(['share_location' => true], json_decode($this->request('POST', '/profile/update-location-sharing', ['share' => '1'])['body'], true));
        $this->assertContains($userId, array_column($this->locations(), 'user_id'));
    }

    /**
     * Everyone's pin, as the map asks for them
     *
     * @return array[]
     */
    private function locations(): array
    {
        $response = $this->request('POST', '/app/get-locations');
        $this->assertSame(200, $response['status']);

        return json_decode($response['body'], true);
    }

    public function testThePinsHaveThePlaceAndNameButNoEmail(): void
    {
        $this->logIn();
        $this->db->prepare("UPDATE userinfo SET lat = 23.8, lng = 90.4, city = 'Dhaka', country = 'BD', online_at = NOW() WHERE email = ?")
            ->execute([self::EMAIL]);

        $userId = (int) $this->db->query("SELECT id FROM users WHERE email = '" . self::EMAIL . "'")->fetchColumn();

        $pins = array_values(array_filter($this->locations(), fn (array $pin): bool => $pin['user_id'] === $userId));

        $this->assertCount(1, $pins);
        $this->assertSame('Dhaka', $pins[0]['city']);
        $this->assertSame('BD', $pins[0]['country']);
        $this->assertArrayNotHasKey('email', $pins[0]);
        $this->assertStringNotContainsString(self::EMAIL, $this->request('POST', '/app/get-locations')['body']);
    }

    public function testThePeopleListHasYouOnlineWithYourTimeZoneButNoEmails(): void
    {
        $this->logIn();

        $response = $this->request('POST', '/app/get-people', ['timezone' => 'Asia/Dhaka']);

        $this->assertSame(200, $response['status']);
        $userId = (int) $this->db->query("SELECT id FROM users WHERE email = '" . self::EMAIL . "'")->fetchColumn();
        $ann = array_values(array_filter(json_decode($response['body'], true), fn (array $person): bool => $person['id'] === $userId));
        $this->assertCount(1, $ann);
        $this->assertTrue((bool) $ann[0]['is_online']);
        $this->assertSame('Asia/Dhaka', $ann[0]['timezone']);
        $this->assertStringNotContainsString(self::EMAIL, $response['body']);
    }
}

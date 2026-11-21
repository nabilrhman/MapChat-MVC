<?php

namespace Tests\Controllers;

use App\Models\Chat;
use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\ApiSpec;
use Tests\Support\DrivesTheSite;
use Tests\Support\TestDb;

/**
 * The HTTP calls the pages make, over real requests, against
 * docs/api/openapi.yaml: each answer's status must be one the spec lists,
 * and its JSON must match the spec's schema, so the spec can't drift from
 * the code. The site has a Groq key, but no request here gets as far as Groq
 */
class OpenApiTest extends TestCase
{
    use DrivesTheSite;

    const EMAIL = 'openapi@test.local';
    const PASSWORD = 'secret123';

    private PDO $db;

    public static function setUpBeforeClass(): void
    {
        self::startSite(['GROQ_API_KEY' => 'test-key-never-sent']);
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
        TestDb::createUser('Pat Openapi', self::EMAIL, self::PASSWORD);
        $this->request('POST', '/login/create', ['email' => self::EMAIL, 'password' => self::PASSWORD]);
        // Online in Boise, so they have a pin. After signing in, which looks 127.0.0.1 up and finds nowhere
        $this->db->prepare("INSERT INTO userinfo (email, ip, lat, lng, city, country, timezone, online_at)
                            VALUES (?, '127.0.0.1', 43.615, -116.2023, 'Boise', 'US', 'America/Boise', NOW())
                            ON DUPLICATE KEY UPDATE lat = VALUES(lat), lng = VALUES(lng), city = VALUES(city),
                                country = VALUES(country), timezone = VALUES(timezone), online_at = NOW()")
            ->execute([self::EMAIL]);
    }

    protected function tearDown(): void
    {
        $this->db->prepare('DELETE FROM chats WHERE email = ?')->execute([self::EMAIL]);
        $this->db->prepare('DELETE FROM userinfo WHERE email = ?')->execute([self::EMAIL]);
        $this->db->prepare('DELETE FROM users WHERE email = ?')->execute([self::EMAIL]);
    }

    public function testThePeopleListMatchesTheSpec(): void
    {
        $response = $this->request('GET', '/people');

        $this->assertMatchesSpec('get', '/people', 200, $response);
        $this->assertStringContainsString('Pat Openapi', $response['body']);
    }

    public function testThePinsMatchTheSpec(): void
    {
        $response = $this->request('GET', '/people/pins');

        $this->assertMatchesSpec('get', '/people/pins', 200, $response);
        $this->assertStringContainsString('Boise', $response['body']);
    }

    public function testNewAndOlderMessagesMatchTheSpec(): void
    {
        $this->request('POST', '/messages/send', ['body' => 'Hello from the spec test']);

        $this->assertMatchesSpec('get', '/messages/new', 200, $this->request('GET', '/messages/new?after=0'));
        $this->assertMatchesSpec('get', '/messages/older', 200, $this->request('GET', '/messages/older?before=999999999&conversation=everyone'));
    }

    public function testAskingForNewMessagesWithoutSayingFromWhereMatchesTheSpec(): void
    {
        $this->assertMatchesSpec('get', '/messages/new', 400, $this->request('GET', '/messages/new'));
    }

    public function testASentMessageMatchesTheSpec(): void
    {
        $response = $this->request('POST', '/messages/send', ['body' => 'Hello from the spec test', 'client_id' => 'openapi-test-1']);

        $this->assertMatchesSpec('post', '/messages/send', 200, $response);
    }

    public function testAnEmptyOrTooLongMessageMatchesTheSpec(): void
    {
        $this->assertMatchesSpec('post', '/messages/send', 422, $this->request('POST', '/messages/send', ['body' => '']));
        $this->assertMatchesSpec('post', '/messages/send', 422, $this->request('POST', '/messages/send', ['body' => str_repeat('a', Chat::MAX_LENGTH + 1)]));
    }

    public function testAMalformedClientIdMatchesTheSpec(): void
    {
        $response = $this->request('POST', '/messages/send', ['body' => 'hello', 'client_id' => 'not a valid id!']);

        $this->assertMatchesSpec('post', '/messages/send', 400, $response);
    }

    public function testMarkingTheRoomReadMatchesTheSpec(): void
    {
        $response = $this->request('POST', '/messages/mark-read', ['conversation' => 'everyone', 'up_to' => 1]);

        $this->assertMatchesSpec('post', '/messages/mark-read', 400, $response);
    }

    public function testATranslationWithNoTextMatchesTheSpec(): void
    {
        $response = $this->request('POST', '/messages/translate', ['text' => '', 'to' => 'fr']);

        $this->assertMatchesSpec('post', '/messages/translate', 422, $response);
    }

    public function testARefusedPushSubscriptionMatchesTheSpec(): void
    {
        $response = $this->request('POST', '/push/subscribe', ['endpoint' => 'https://example.com/push', 'p256dh' => 'key', 'auth' => 'secret']);

        $this->assertMatchesSpec('post', '/push/subscribe', 400, $response);
    }

    public function testUnsubscribingFromPushMatchesTheSpec(): void
    {
        $response = $this->request('POST', '/push/unsubscribe', ['endpoint' => 'https://fcm.googleapis.com/fcm/send/none']);

        $this->assertMatchesSpec('post', '/push/unsubscribe', 200, $response);
    }

    public function testHidingFromTheMapMatchesTheSpec(): void
    {
        $response = $this->request('POST', '/profile/update-location-sharing', ['share' => '0']);

        $this->assertMatchesSpec('post', '/profile/update-location-sharing', 200, $response);
        $this->assertSame('{"share_location":false}', $response['body']);
    }

    public function testSavingTheTimeZoneMatchesTheSpec(): void
    {
        $response = $this->request('POST', '/profile/update-timezone', ['timezone' => 'Europe/Lisbon']);

        $this->assertMatchesSpec('post', '/profile/update-timezone', 200, $response);
        $timezones = array_column(json_decode($this->request('GET', '/people')['body'], true), 'timezone');
        $this->assertContains('Europe/Lisbon', $timezones);
    }

    public function testAnEmailCheckMatchesTheSpec(): void
    {
        $response = $this->request('POST', '/account/check-email', ['email' => self::EMAIL]);

        $this->assertMatchesSpec('post', '/account/check-email', 200, $response);
    }

    public function testASignedOutCallMatchesTheSpec(): void
    {
        $this->request('POST', '/logout');

        $response = $this->request('GET', '/people', [], ['X-Requested-With' => 'XMLHttpRequest']);

        $this->assertMatchesSpec('get', '/people', 401, $response);
    }

    public function testAPostFromAnotherSiteMatchesTheSpec(): void
    {
        $response = $this->request('POST', '/messages/send', ['body' => 'hello'], ['Origin' => 'https://evil.example']);

        $this->assertMatchesSpec('post', '/messages/send', 403, $response);
    }

    /**
     * Assert the answer has the status expected, that the spec lists that
     * status for the call, and that a JSON answer matches the spec's schema
     *
     * @param string $method As the spec writes it: get or post
     * @param array{status: int, headers: array<string, string>, body: string} $response
     */
    private function assertMatchesSpec(string $method, string $path, int $expectedStatus, array $response): void
    {
        $this->assertSame($expectedStatus, $response['status'], $response['body']);

        $call = ApiSpec::read('openapi.yaml')['paths'][$path][$method] ?? null;
        $this->assertNotNull($call, "The spec has no $method $path");
        $answer = $call['responses'][$response['status']] ?? null;
        $this->assertNotNull($answer, "The spec doesn't list $response[status] for $method $path");

        $responsePointer = '/paths/' . self::pointerPart($path) . "/$method/responses/$response[status]";
        // A shared answer is a $ref to one in components/responses
        if (isset($answer['$ref'])) {
            $responsePointer = substr($answer['$ref'], 1);
            $answer = ApiSpec::read('openapi.yaml')['components']['responses'][basename($answer['$ref'])];
        }

        if (!isset($answer['content']['application/json'])) {
            return;
        }
        $this->assertStringStartsWith('application/json', $response['headers']['content-type'] ?? '');
        $schemaPointer = "$responsePointer/content/application~1json/schema";
        $this->assertSame([], ApiSpec::errorsIn(json_decode($response['body']), 'openapi.yaml', $schemaPointer));
    }

    /**
     * A key as one part of a JSON pointer, where / is written ~1
     */
    private static function pointerPart(string $key): string
    {
        return str_replace(['~', '/'], ['~0', '~1'], $key);
    }
}

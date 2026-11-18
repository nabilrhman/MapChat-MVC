<?php

namespace Tests\Notify;

use App\Controllers\Push as PushController;
use App\Models\PushSubscription;
use App\Notify\PushNotifications;
use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\TestDb;

/**
 * Web Push: whose browsers a message goes to, what it says, and keeping
 * each browser with the right person. Each test runs in a transaction that
 * is rolled back
 */
class PushTest extends TestCase
{
    const ANN = 'ann@test.local';
    const BOB = 'bob@test.local';
    const CAT = 'cat@test.local';

    private PDO $db;

    protected function setUp(): void
    {
        $this->db = TestDb::get();
        $this->db->beginTransaction();

        foreach ([self::ANN => 'Ann Lee', self::BOB => 'Bob Ray', self::CAT => 'Cat Kim'] as $email => $name) {
            TestDb::createUser($name, $email);
        }
    }

    protected function tearDown(): void
    {
        $this->db->rollBack();
    }

    /**
     * Each person's browser, as PushSubscription::getAll gives them
     */
    private function subscribeEveryone(): array
    {
        foreach ([self::ANN, self::BOB, self::CAT] as $email) {
            PushSubscription::save($email, "https://push.example.com/$email", 'key', 'secret');
        }

        return PushSubscription::getAll();
    }

    /**
     * Whose subscriptions these are
     */
    private function emailsOf(array $subscriptions): array
    {
        return array_column($subscriptions, 'email');
    }

    public function testAPrivateMessageGoesToTheOtherPersonOnly(): void
    {
        $chat = ['email' => self::ANN, 'to_email' => self::BOB, 'body' => 'hi @Cat'];

        $this->assertSame([self::BOB], $this->emailsOf(PushNotifications::recipientsOf($chat, $this->subscribeEveryone())));
    }

    public function testARoomMessageGoesToWhoeverItMentions(): void
    {
        $subscriptions = $this->subscribeEveryone();

        $mentionsCat = ['email' => self::ANN, 'to_email' => null, 'body' => 'what do you think, @cat?'];
        $this->assertSame([self::CAT], $this->emailsOf(PushNotifications::recipientsOf($mentionsCat, $subscriptions)));

        $mentionsNobody = ['email' => self::ANN, 'to_email' => null, 'body' => 'hello all'];
        $this->assertSame([], PushNotifications::recipientsOf($mentionsNobody, $subscriptions));
    }

    public function testNobodyIsPushedTheirOwnMessage(): void
    {
        $chat = ['email' => self::ANN, 'to_email' => null, 'body' => 'talking to myself, @Ann'];

        $this->assertSame([], PushNotifications::recipientsOf($chat, $this->subscribeEveryone()));
    }

    public function testTheNotificationNamesTheSenderAndOpensTheirChat(): void
    {
        $chat = [
            'user_id' => 7, 'name' => 'Ann Lee', 'nickname' => null, 'is_guest' => false, 'avatar' => null, 'is_demo' => false,
            'to_email' => self::BOB, 'body' => str_repeat('a', 200),
        ];

        $private = PushNotifications::payloadOf($chat, []);
        $this->assertSame('Ann', $private['title']);
        $this->assertSame('7', $private['conversation']);
        $this->assertSame('chat-7', $private['tag']);
        $this->assertSame(PushNotifications::BODY_LENGTH, mb_strlen($private['body']));
        $this->assertStringEndsWith('…', $private['body']);

        $mention = PushNotifications::payloadOf(['to_email' => null] + $chat, []);
        $this->assertSame('Ann mentioned you', $mention['title']);
        $this->assertSame('everyone', $mention['conversation']);
    }

    public function testABrowserMovesToWhoeverSubscribesItLast(): void
    {
        PushSubscription::save(self::ANN, 'https://push.example.com/shared', 'key', 'secret');
        PushSubscription::save(self::BOB, 'https://push.example.com/shared', 'key2', 'secret2');

        $this->assertSame([self::BOB], $this->emailsOf(PushSubscription::getAll()));
    }

    public function testOnlyItsOwnerCanForgetABrowser(): void
    {
        PushSubscription::save(self::ANN, 'https://push.example.com/ann', 'key', 'secret');

        PushSubscription::delete(self::BOB, 'https://push.example.com/ann');
        $this->assertSame([self::ANN], $this->emailsOf(PushSubscription::getAll()));

        PushSubscription::delete(self::ANN, 'https://push.example.com/ann');
        $this->assertSame([], PushSubscription::getAll());
    }

    public function testOnlyPushServiceAddressesAreSaved(): void
    {
        $this->assertTrue(PushController::isPushEndpoint('https://fcm.googleapis.com/fcm/send/abc'));
        $this->assertFalse(PushController::isPushEndpoint('http://fcm.googleapis.com/fcm/send/abc'));
        $this->assertFalse(PushController::isPushEndpoint('https://127.0.0.1/send'));
        $this->assertFalse(PushController::isPushEndpoint('https://localhost/send'));
        $this->assertFalse(PushController::isPushEndpoint('https://push.example.com/' . str_repeat('a', 2000)));
    }
}

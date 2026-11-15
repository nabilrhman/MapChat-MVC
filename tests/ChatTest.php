<?php

namespace Tests;

use App\Models\Chat;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Gives the tests the app's own database connection
 */
class TestDb extends \Core\Model
{
    public static function get(): PDO
    {
        return static::getDB();
    }
}

/**
 * Who sees which messages. Each test runs in a transaction that is rolled back
 */
class ChatTest extends TestCase
{
    const ANN = 'ann@test.local';
    const BOB = 'bob@test.local';
    const CAT = 'cat@test.local';

    private PDO $db;

    protected function setUp(): void
    {
        $this->db = TestDb::get();
        $this->db->beginTransaction();

        foreach ([self::ANN, self::BOB, self::CAT] as $email) {
            $this->db->prepare("INSERT INTO users (name, email, password_hash, is_active) VALUES (?, ?, '!', TRUE)")
                ->execute([strtok($email, '@'), $email]);
            $this->db->prepare("INSERT INTO userinfo (email, ip, lat, lng, city, country) VALUES (?, '8.8.8.8', 1, 2, 'Dhaka', 'BD')")
                ->execute([$email]);
        }
    }

    protected function tearDown(): void
    {
        $this->db->rollBack();
    }

    /**
     * Send a message and return its id
     */
    private function send(string $from, string $body, ?string $to = null): int
    {
        (new Chat(['email' => $from, 'to_email' => $to, 'body' => $body]))->send();

        return (int) $this->db->lastInsertId();
    }

    /**
     * The bodies of the test people's messages, leaving out the welcome ones
     */
    private function bodies(array $chats): array
    {
        $ours = array_filter($chats, fn ($chat) => $chat['email'] !== Chat::BOT_EMAIL);

        return array_values(array_column($ours, 'body'));
    }

    public function testEveryoneSeesTheOpenRoom()
    {
        $this->send(self::ANN, 'hello all');

        $this->assertSame(['hello all'], $this->bodies(Chat::getRecent(self::BOB)));
    }

    public function testPrivateMessageOnlyReachesSenderAndRecipient()
    {
        $this->send(self::ANN, 'just for bob', self::BOB);

        $this->assertSame(['just for bob'], $this->bodies(Chat::getRecent(self::ANN)));
        $this->assertSame(['just for bob'], $this->bodies(Chat::getRecent(self::BOB)));
        $this->assertSame([], $this->bodies(Chat::getRecent(self::CAT)));
    }

    public function testRecentIsOldestFirst()
    {
        $this->send(self::ANN, 'first');
        $this->send(self::BOB, 'second', self::ANN);
        $this->send(self::ANN, 'third', self::CAT);

        $this->assertSame(['first', 'second', 'third'], $this->bodies(Chat::getRecent(self::ANN)));
    }

    public function testRecentKeepsOnlyTheNewest()
    {
        $values = implode(',', array_fill(0, Chat::HISTORY + 5, "('" . self::ANN . "', 'm')"));
        $this->db->exec("INSERT INTO chats (email, body) VALUES $values");
        $last = $this->send(self::ANN, 'newest');

        $chats = Chat::getRecent(self::BOB);

        $this->assertCount(Chat::HISTORY, $chats);
        $this->assertSame($last, (int) end($chats)['id']);
    }

    public function testRecentMixesOpenAndPrivateByAge()
    {
        // Lots of newer open-room messages must not hide an older private one
        // past the limit, nor let it jump ahead of them
        $private = $this->send(self::BOB, 'old private', self::ANN);
        $values = implode(',', array_fill(0, Chat::HISTORY, "('" . self::CAT . "', 'm')"));
        $this->db->exec("INSERT INTO chats (email, body) VALUES $values");

        $ids = array_map('intval', array_column(Chat::getRecent(self::ANN), 'id'));

        $this->assertCount(Chat::HISTORY, $ids);
        $this->assertNotContains($private, $ids);
    }

    public function testAfterReturnsOnlyNewerVisibleMessages()
    {
        $seen = $this->send(self::ANN, 'already seen');
        $this->send(self::BOB, 'new');
        $this->send(self::BOB, 'for cat only', self::CAT);
        $this->send(self::CAT, 'for ann', self::ANN);

        $this->assertSame(['new', 'for ann'], $this->bodies(Chat::getAfter(self::ANN, $seen)));
    }

    public function testBeforeReturnsOnlyOlderVisibleMessagesOldestFirst()
    {
        $this->send(self::ANN, 'old');
        $this->send(self::BOB, 'for cat only', self::CAT);
        $this->send(self::CAT, 'for ann', self::ANN);
        $this->send(self::CAT, 'from cat to bob', self::BOB);
        $first = $this->send(self::ANN, 'first shown');
        $this->send(self::BOB, 'newer');

        $this->assertSame(['old', 'for ann'], $this->bodies(Chat::getBefore(self::ANN, $first)));
    }

    public function testBeforeKeepsOnlyThePageJustBefore()
    {
        $oldest = $this->send(self::ANN, 'too old');
        $values = implode(',', array_fill(0, Chat::HISTORY, "('" . self::BOB . "', 'm')"));
        $this->db->exec("INSERT INTO chats (email, body) VALUES $values");
        $first = $this->send(self::ANN, 'first shown');

        $ids = array_map('intval', array_column(Chat::getBefore(self::ANN, $first), 'id'));

        $this->assertCount(Chat::HISTORY, $ids);
        $this->assertNotContains($oldest, $ids);
        $this->assertSame($first - 1, end($ids));
    }

    public function testHiddenLocationIsNotSent()
    {
        $this->db->prepare('UPDATE users SET share_location = FALSE WHERE email = ?')->execute([self::ANN]);
        $this->send(self::ANN, 'where am i');
        $this->send(self::BOB, 'here');

        $chats = array_column(Chat::getRecent(self::CAT), null, 'body');

        $this->assertNull($chats['where am i']['city']);
        $this->assertNull($chats['where am i']['lng']);
        $this->assertSame('Dhaka', $chats['here']['city']);
    }

    public function testBodyIsStoredAsTyped()
    {
        // Escaping happens in the template, not before saving
        $this->send(self::ANN, '<b>hi</b> & bye');

        $this->assertSame(['<b>hi</b> & bye'], $this->bodies(Chat::getRecent(self::ANN)));
    }

    public function testCountRecentOnlyCountsTheSendersRecentMessages()
    {
        $this->send(self::ANN, 'one');
        $this->send(self::ANN, 'two', self::BOB);
        $this->send(self::BOB, 'not ann');
        $old = $this->send(self::ANN, 'long ago');
        $this->db->exec("UPDATE chats SET timestamp = NOW() - INTERVAL 5 MINUTE WHERE id = $old");

        $this->assertSame(2, Chat::countRecent(self::ANN, 60));
    }
}

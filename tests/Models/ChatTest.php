<?php

namespace Tests\Models;

use App\Messages\Mention;
use App\Messages\MessageText;
use App\Models\Chat;
use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\TestDb;

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
            TestDb::createUser(strtok($email, '@'), $email);
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
        return Chat::save($from, $to, new MessageText($body));
    }

    /**
     * The bodies of the test people's messages, leaving out the welcome ones
     */
    private function bodies(array $chats): array
    {
        $ours = array_filter($chats, fn ($chat) => $chat['email'] !== Chat::BOT_EMAIL);

        return array_values(array_column($ours, 'body'));
    }

    public function testEveryoneSeesTheOpenRoom(): void
    {
        $this->send(self::ANN, 'hello all');

        $this->assertSame(['hello all'], $this->bodies(Chat::getRecent(self::BOB)));
    }

    public function testPrivateMessageOnlyReachesSenderAndRecipient(): void
    {
        $this->send(self::ANN, 'just for bob', self::BOB);

        $this->assertSame(['just for bob'], $this->bodies(Chat::getRecent(self::ANN)));
        $this->assertSame(['just for bob'], $this->bodies(Chat::getRecent(self::BOB)));
        $this->assertSame([], $this->bodies(Chat::getRecent(self::CAT)));
    }

    public function testRecentIsOldestFirst(): void
    {
        $this->send(self::ANN, 'first');
        $this->send(self::BOB, 'second', self::ANN);
        $this->send(self::ANN, 'third', self::CAT);

        $this->assertSame(['first', 'second', 'third'], $this->bodies(Chat::getRecent(self::ANN)));
    }

    public function testRecentKeepsOnlyTheNewest(): void
    {
        $values = implode(',', array_fill(0, Chat::HISTORY + 5, "('" . self::ANN . "', 'm')"));
        $this->db->exec("INSERT INTO chats (email, body) VALUES $values");
        $last = $this->send(self::ANN, 'newest');

        $chats = Chat::getRecent(self::BOB);

        $this->assertCount(Chat::HISTORY, $chats);
        $this->assertSame($last, (int) end($chats)['id']);
    }

    public function testRecentMixesOpenAndPrivateByAge(): void
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

    public function testAfterReturnsOnlyNewerVisibleMessages(): void
    {
        $seen = $this->send(self::ANN, 'already seen');
        $this->send(self::BOB, 'new');
        $this->send(self::BOB, 'for cat only', self::CAT);
        $this->send(self::CAT, 'for ann', self::ANN);

        $this->assertSame(['new', 'for ann'], $this->bodies(Chat::getAfter(self::ANN, $seen)));
    }

    /**
     * Polls the way messages.js does: keep only ids past the cursor, and move
     * the cursor to the last one kept
     */
    private function poll(string $viewer, int &$cursor, ?array $chats = null): array
    {
        $chats ??= Chat::getAfter($viewer, $cursor);
        $kept = array_values(array_filter(array_map('intval', array_column($chats, 'id')), fn ($id) => $id > $cursor));
        if ($kept) {
            $cursor = end($kept);
        }

        return $kept;
    }

    /**
     * Rows MySQL has read through this connection so far
     */
    private function rowsRead(): int
    {
        $rows = $this->db->query("SHOW SESSION STATUS LIKE 'Handler_read%'")->fetchAll(PDO::FETCH_KEY_PAIR);

        return array_sum(array_map('intval', $rows));
    }

    /**
     * Save many messages at once, straight into the table
     */
    private function sendMany(string $from, int $count, ?string $to = null): void
    {
        $values = implode(',', array_fill(0, $count, '(' . $this->db->quote($from) . ', '
            . ($to === null ? 'NULL' : $this->db->quote($to)) . ", 'm')"));
        $this->db->exec("INSERT INTO chats (email, to_email, body) VALUES $values");
    }

    public function testAfterReachesAnIdleViewerOnceOthersHaveChattedPrivately(): void
    {
        $cursor = $this->send(self::ANN, 'already seen');
        $this->sendMany(self::BOB, 300, self::CAT);
        $this->sendMany(self::CAT, 300, self::BOB);

        // Ann's page finds nothing, so its cursor stays put
        for ($i = 0; $i < 3; $i++) {
            $this->assertSame([], $this->poll(self::ANN, $cursor));
        }

        $forAnn = $this->send(self::CAT, 'for ann', self::ANN);

        $this->assertSame([$forAnn], $this->poll(self::ANN, $cursor));
    }

    public function testAfterDoesNotReadOtherPeoplesPrivateMessages(): void
    {
        // With a long open-room history MySQL reads every newer message in id
        // order rather than merge the indexes; with a short one this test
        // passes either way
        $this->sendMany(self::BOB, 5000);
        $cursor = $this->send(self::ANN, 'already seen');
        $this->sendMany(self::BOB, 2000, self::CAT);

        $before = $this->rowsRead();
        Chat::getAfter(self::ANN, $cursor);

        $this->assertLessThan(50, $this->rowsRead() - $before);
    }

    public function testAfterPagesThroughABacklogWithoutGapsOrRepeats(): void
    {
        $cursor = $this->send(self::ANN, 'already seen');
        $expected = [];
        for ($i = 0; $i < 3 * Chat::HISTORY; $i++) {
            [$from, $to, $visible] = [
                [self::BOB, null, true],
                [self::BOB, self::CAT, false],
                [self::CAT, self::ANN, true],
                [self::CAT, self::BOB, false],
                [self::ANN, self::BOB, true],
            ][$i % 5];
            $id = $this->send($from, "m$i", $to);
            if ($visible) {
                $expected[] = $id;
            }
        }

        $shown = [];
        while ($page = $this->poll(self::ANN, $cursor)) {
            $this->assertLessThanOrEqual(Chat::HISTORY, count($page));
            array_push($shown, ...$page);
        }

        $this->assertSame($expected, $shown);
    }

    public function testOverlappingPollsShowEachMessageOnce(): void
    {
        $cursor = $this->send(self::ANN, 'already seen');
        $this->sendMany(self::BOB, Chat::HISTORY + 20);

        // Two polls leave with the same cursor; more arrives between them, and
        // the later reply comes back first
        $early = Chat::getAfter(self::ANN, $cursor);
        $this->sendMany(self::CAT, 30, self::ANN);
        $late = Chat::getAfter(self::ANN, $cursor);

        $shown = array_merge($this->poll(self::ANN, $cursor, $late), $this->poll(self::ANN, $cursor, $early));
        while ($page = $this->poll(self::ANN, $cursor)) {
            array_push($shown, ...$page);
        }

        $this->assertCount(Chat::HISTORY + 50, $shown);
        $this->assertSame($shown, array_values(array_unique($shown)));
        $sorted = $shown;
        sort($sorted);
        $this->assertSame($sorted, $shown);
    }

    public function testBeforeReturnsOnlyOlderVisibleMessagesOldestFirst(): void
    {
        $this->send(self::ANN, 'old');
        $this->send(self::BOB, 'for cat only', self::CAT);
        $this->send(self::CAT, 'for ann', self::ANN);
        $this->send(self::CAT, 'from cat to bob', self::BOB);
        $first = $this->send(self::ANN, 'first shown');
        $this->send(self::BOB, 'newer');

        $this->assertSame(['old', 'for ann'], $this->bodies(Chat::getBefore(self::ANN, $first)));
    }

    public function testBeforeKeepsOnlyThePageJustBefore(): void
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

    public function testHiddenLocationIsNotSent(): void
    {
        $this->db->prepare('UPDATE users SET share_location = FALSE WHERE email = ?')->execute([self::ANN]);
        $this->send(self::ANN, 'where am i');
        $this->send(self::BOB, 'here');

        $chats = array_column(Chat::getRecent(self::CAT), null, 'body');

        $this->assertNull($chats['where am i']['city']);
        $this->assertNull($chats['where am i']['lng']);
        $this->assertSame('Dhaka', $chats['here']['city']);
    }

    public function testBodyIsStoredAsTyped(): void
    {
        // Escaping happens in the template, not before saving
        $this->send(self::ANN, '<b>hi</b> & bye');

        $this->assertSame(['<b>hi</b> & bye'], $this->bodies(Chat::getRecent(self::ANN)));
    }

    public function testCountRecentOnlyCountsTheSendersRecentMessages(): void
    {
        $this->send(self::ANN, 'one');
        $this->send(self::ANN, 'two', self::BOB);
        $this->send(self::BOB, 'not ann');
        $old = $this->send(self::ANN, 'long ago');
        $this->db->exec("UPDATE chats SET timestamp = NOW() - INTERVAL 5 MINUTE WHERE id = $old");

        $this->assertSame(2, Chat::countRecent(self::ANN, 60));
    }

    /**
     * Someone's id
     */
    private function userId(string $email): int
    {
        $stmt = $this->db->prepare('SELECT id FROM users WHERE email = ?');
        $stmt->execute([$email]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * Private chats as partner name => [unread, newest id]
     */
    private function conversations(string $viewer, int $upToId = PHP_INT_MAX): array
    {
        $conversations = [];
        foreach (Chat::getConversations($viewer, $upToId) as $partner) {
            $conversations[$partner['name']] = [(int) $partner['unread'], (int) $partner['newest_id']];
        }

        return $conversations;
    }

    public function testConversationsCountUnreadPerPartner(): void
    {
        $this->send(self::ANN, 'one', self::BOB);
        $annNewest = $this->send(self::ANN, 'two', self::BOB);
        $catNewest = $this->send(self::CAT, 'hi bob', self::BOB);
        $this->send(self::ANN, 'hello all');

        $this->assertSame(['cat' => [1, $catNewest], 'ann' => [2, $annNewest]], $this->conversations(self::BOB));
    }

    public function testConversationsIncludeOnesYouStartedWithNothingUnread(): void
    {
        $sent = $this->send(self::ANN, 'for cat', self::CAT);

        $this->assertSame(['cat' => [0, $sent]], $this->conversations(self::ANN));
        $this->assertSame([], $this->conversations(self::BOB));
    }

    public function testConversationsIncludeOnesPushedOutOfTheNewestPage(): void
    {
        $private = $this->send(self::ANN, 'before the rush', self::BOB);
        for ($i = 0; $i < Chat::HISTORY; $i++) {
            $this->send(self::CAT, "busy $i");
        }

        $this->assertNotContains('before the rush', $this->bodies(Chat::getRecent(self::BOB)));
        $this->assertSame(['ann' => [1, $private]], $this->conversations(self::BOB));
    }

    public function testConversationsLeaveOutMessagesNewerThanThePage(): void
    {
        $first = $this->send(self::ANN, 'on the page', self::BOB);
        $this->send(self::ANN, 'after the page', self::BOB);

        $this->assertSame(['ann' => [1, $first]], $this->conversations(self::BOB, $first));
    }

    public function testMarkReadOnlyClearsThatSenderUpToTheId(): void
    {
        $this->send(self::ANN, 'one', self::BOB);
        $seen = $this->send(self::ANN, 'two', self::BOB);
        $later = $this->send(self::ANN, 'three', self::BOB);
        $catMessage = $this->send(self::CAT, 'hi bob', self::BOB);

        Chat::markRead(self::BOB, $this->userId(self::ANN), $seen);

        $this->assertSame(['cat' => [1, $catMessage], 'ann' => [1, $later]], $this->conversations(self::BOB));
    }

    public function testMarkReadCantClearSomeoneElsesMessages(): void
    {
        $sent = $this->send(self::ANN, 'for cat', self::CAT);

        // Bob marking "ann" read touches only messages sent to bob
        Chat::markRead(self::BOB, $this->userId(self::ANN), PHP_INT_MAX);

        $this->assertSame(['ann' => [1, $sent]], $this->conversations(self::CAT));
    }

    /**
     * Someone's account
     */
    private function user(string $email): \App\Models\User
    {
        return \App\Models\User::findByEmail($email);
    }

    public function testSendFromWithTheSameClientIdSavesOnce(): void
    {
        $first = Chat::sendFrom($this->user(self::ANN), new MessageText('once'), $this->userId(self::BOB), 'client-id-1');
        $again = Chat::sendFrom($this->user(self::ANN), new MessageText('once'), $this->userId(self::BOB), 'client-id-1');

        $this->assertTrue($first->isSent());
        $this->assertSame($first->id, $again->id);
        $this->assertSame(['once'], $this->bodies(Chat::getRecent(self::BOB)));
    }

    public function testSendFromKeepsClientIdsPerSender(): void
    {
        $ann = Chat::sendFrom($this->user(self::ANN), new MessageText('from ann'), null, 'shared-id-1');
        $bob = Chat::sendFrom($this->user(self::BOB), new MessageText('from bob'), null, 'shared-id-1');

        $this->assertNotSame($ann->id, $bob->id);
    }

    public function testSendFromAppliesTheChatsRules(): void
    {
        $this->assertSame(400, Chat::sendFrom($this->user(self::ANN), new MessageText(str_repeat('x', Chat::MAX_LENGTH + 1)))->status);
        $this->assertSame(400, Chat::sendFrom($this->user(self::ANN), new MessageText('   '))->status);
        $this->assertSame(400, Chat::sendFrom($this->user(self::ANN), new MessageText('to me'), $this->userId(self::ANN))->status);
        $this->assertSame(400, Chat::sendFrom($this->user(self::ANN), new MessageText('bad id'), null, 'not a valid id!')->status);

        $this->sendMany(self::ANN, Chat::USER_PER_MINUTE);
        $this->assertSame(429, Chat::sendFrom($this->user(self::ANN), new MessageText('one too many'))->status);
    }

    public function testSendFromKeepsWhatWasTypedBeforeItWasTranslated(): void
    {
        $sent = Chat::sendFrom($this->user(self::ANN), new MessageText('Hello', 'হ্যালো'));

        $this->assertTrue($sent->isSent());
        $newest = array_last(Chat::getRecent(self::BOB));
        $this->assertSame('হ্যালো', $newest['original_body']);
    }

    public function testAnOriginalThatSaysTheSameIsNoTranslation(): void
    {
        Chat::sendFrom($this->user(self::ANN), new MessageText('Hello', ' Hello '));
        Chat::sendFrom($this->user(self::ANN), new MessageText('Hi', '  '));

        $newestTwo = array_slice(Chat::getRecent(self::BOB), -2);
        $this->assertSame([null, null], array_column($newestTwo, 'original_body'));
    }

    public function testSomeoneMentionedOnlyInAnOriginalIsFoundForItsChips(): void
    {
        $names = Mention::namesIn([['body' => 'hola a todos', 'original_body' => 'hi everyone @bob']]);

        $this->assertContains('bob', $names);
    }

    public function testAnOriginalOverTheLimitIsRefused(): void
    {
        $sent = Chat::sendFrom($this->user(self::ANN), new MessageText('Hello', str_repeat('x', Chat::MAX_LENGTH + 1)));

        $this->assertSame(400, $sent->status);
    }

    public function testAfterStopsAtTheGivenId(): void
    {
        $start = $this->send(self::ANN, 'start');
        $upTo = $this->send(self::BOB, 'included');
        $this->send(self::BOB, 'not yet');

        $this->assertSame(['included'], $this->bodies(Chat::getAfter(self::ANN, $start, $upTo)));
    }

    public function testPrivateBeforeReadsOnlyThatChat(): void
    {
        $this->send(self::ANN, 'ann to bob', self::BOB);
        $this->send(self::BOB, 'bob to ann', self::ANN);
        $this->send(self::CAT, 'cat to bob', self::BOB);
        $this->send(self::ANN, 'room');
        $this->send(self::CAT, 'cat to ann', self::ANN);

        $this->assertSame(['ann to bob', 'bob to ann'], $this->bodies(Chat::getPrivateBefore(self::BOB, $this->userId(self::ANN), PHP_INT_MAX)));
    }

    public function testRoomBeforeLeavesOutPrivateMessages(): void
    {
        $this->send(self::ANN, 'room one');
        $this->send(self::ANN, 'private', self::BOB);
        $before = $this->send(self::BOB, 'room two');

        $this->assertSame(['room one'], $this->bodies(Chat::getRoomBefore($before)));
    }
}

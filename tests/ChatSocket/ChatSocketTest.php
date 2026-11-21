<?php

namespace Tests\ChatSocket;

use App\ChatSocket\Handshake;
use App\ChatSocket\Hub;
use App\Messages\MessageText;
use App\Models\Chat;
use App\Models\User;
use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\ApiSpec;
use Tests\Support\SocketClient;
use Tests\Support\TestDb;

/**
 * The chat socket (bin/chat-socket.php), run as its own process against the
 * test database, with pages played by SocketClient: who gets which message,
 * sending again after a lost answer, catching up after a restart, typing,
 * presence, and who's let in
 */
class ChatSocketTest extends TestCase
{
    const PEOPLE = ['ann', 'bob', 'cat'];

    /** Short, so presence and logout tests don't wait long */
    const GRACE_SECONDS = 1;
    const SESSION_CHECK_SECONDS = 1;

    private static $server = null;
    private static int $port;
    private static string $address;

    private PDO $db;

    /**
     * The test people's emails, users and sessions, by name. Each test has
     * new people, so one test's leaving can't be taken for the next one's
     */
    private array $emails = [];
    private array $users = [];
    private array $sessions = [];

    /** @var SocketClient[] Closed after each test */
    private array $clients = [];

    public static function setUpBeforeClass(): void
    {
        // A free port: bind to 0, read the port back, then let it go
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        self::$address = stream_socket_get_name($socket, false);
        fclose($socket);
        self::$port = (int) explode(':', self::$address)[1];

        // Messages this process saves signal the test's socket, not a real one
        putenv('CHAT_SOCKET_PORT=' . self::$port);

        self::startServer();

        // Other tests' rolled-back transactions used up ids, and the socket
        // waits out a gap in the ids once (NewMessageReader::GAP_SECONDS) before reading on
        Chat::save(Chat::BOT_EMAIL, null, new MessageText('warming up'));
        usleep((int) ((\App\ChatSocket\NewMessageReader::GAP_SECONDS + 0.5) * 1000000));
        TestDb::get()->exec("DELETE FROM chats WHERE body = 'warming up'");
    }

    public static function tearDownAfterClass(): void
    {
        self::stopServer();
        putenv('CHAT_SOCKET_PORT');
    }

    /**
     * Start the chat socket on the test port, and wait for it to listen
     */
    private static function startServer(): void
    {
        // The test's settings first, so the same ones in the environment don't win
        $environment = [
            'CHAT_SOCKET_PORT' => self::$port,
            'CHAT_PRESENCE_GRACE_SECONDS' => self::GRACE_SECONDS,
            'CHAT_SESSION_CHECK_SECONDS' => self::SESSION_CHECK_SECONDS,
        ] + getenv();
        $command = [PHP_BINARY, dirname(__DIR__, 2) . '/bin/chat-socket.php'];
        // Its log, for when a test fails
        $log = ['file', sys_get_temp_dir() . '/chat-socket-test.log', 'a'];
        self::$server = proc_open($command, [1 => $log, 2 => $log], $pipes, null, $environment);

        for ($tries = 0; $tries < 50; $tries++) {
            $connection = @fsockopen('tcp://' . self::$address);
            if ($connection) {
                fclose($connection);
                return;
            }
            usleep(100000);
        }
        throw new \RuntimeException('The chat socket did not start on ' . self::$address);
    }

    /**
     * Stop it as a deploy does, with SIGTERM, and wait for it to exit
     */
    private static function stopServer(): void
    {
        if (self::$server === null) {
            return;
        }
        proc_terminate(self::$server, SIGTERM);
        for ($tries = 0; $tries < 50 && proc_get_status(self::$server)['running']; $tries++) {
            usleep(100000);
        }
        proc_close(self::$server);
        self::$server = null;
    }

    protected function setUp(): void
    {
        // The socket is another process, so these rows are committed, not
        // rolled back; tearDown deletes them
        $this->db = TestDb::get();
        foreach (self::PEOPLE as $name) {
            $email = "$name-" . bin2hex(random_bytes(4)) . '@socket.test';
            $this->emails[$name] = $email;
            TestDb::createUser(ucfirst($name), $email);
            $this->db->prepare("INSERT INTO userinfo (email, ip) VALUES (?, '127.0.0.1')")->execute([$email]);
            $user = User::findByEmail($email);
            $this->users[$name] = $user;

            $sessionId = bin2hex(random_bytes(16));
            $this->db->prepare('INSERT INTO sessions (id, data, updated_at) VALUES (?, ?, ?)')
                ->execute([$sessionId, 'user_id|i:' . $user->id . ';sign_in_stamp|' . serialize($user->signInStamp()), time()]);
            $this->sessions[$name] = $sessionId;
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->clients as $client) {
            $client->close();
        }
        foreach (self::PEOPLE as $name) {
            $this->db->prepare('DELETE FROM sessions WHERE id = ?')->execute([$this->sessions[$name]]);
            // Their messages and userinfo go with them
            $this->db->prepare('DELETE FROM users WHERE email = ?')->execute([$this->emails[$name]]);
        }
        // Let the socket see them go before the next test's people arrive
        usleep(200000);
    }

    /**
     * Open a socket as someone, with their session, from this site or the given origin
     */
    private function connect(string $name, ?string $origin = null): SocketClient
    {
        $client = new SocketClient(self::$address, $this->sessions[$name] ?? null, $origin);
        $this->clients[] = $client;

        return $client;
    }

    /**
     * Connect and say hello from the newest message, as a page just opened
     */
    private function openPage(string $name): SocketClient
    {
        $client = $this->connect($name);
        $this->assertSame(101, $client->status);
        $client->hello(Chat::getNewestId());

        return $client;
    }

    /**
     * Send a message over the socket and wait for its answer
     */
    private function sendOver(SocketClient $client, string $body, ?string $to = null, ?string $clientId = null): array
    {
        $clientId ??= bin2hex(random_bytes(8));
        $client->send(['type' => 'send', 'client_id' => $clientId, 'body' => $body, 'to' => $to === null ? null : (int) $this->users[$to]->id]);

        return $client->waitFor('sent', 5, fn ($message) => $message['client_id'] === $clientId)
            ?? $this->fail("No answer to sending \"$body\"");
    }

    /**
     * The message bodies and ids in the chats messages received, in order
     *
     * @return array [[id, body], ...]
     */
    private static function chatsIn(array $messages): array
    {
        $chats = [];
        foreach ($messages as $message) {
            if ($message['type'] !== 'chats') {
                continue;
            }
            preg_match_all('#data-id="(\d+)"[\s\S]*?<p class="body" data-part="message-body">([^<]*)</p>#', $message['html'], $matches, PREG_SET_ORDER);
            foreach ($matches as [, $id, $body]) {
                $chats[] = [(int) $id, html_entity_decode($body, ENT_QUOTES)];
            }
        }

        return $chats;
    }

    /**
     * The bodies of the messages the socket sends in the next few seconds
     */
    private function bodiesReceived(SocketClient $client, float $seconds): array
    {
        return array_column(self::chatsIn($client->collect($seconds)), 1);
    }

    public function testPrivateMessageReachesOnlyThePair(): void
    {
        $ann = $this->openPage('ann');
        $bob = $this->openPage('bob');
        $cat = $this->openPage('cat');

        $this->sendOver($ann, 'just for bob', 'bob');

        $this->assertSame(['just for bob'], $this->bodiesReceived($ann, 1));
        $this->assertSame(['just for bob'], $this->bodiesReceived($bob, 0.5));
        $this->assertSame([], $this->bodiesReceived($cat, 0.5));
    }

    public function testOpenRoomMessageReachesEveryone(): void
    {
        $ann = $this->openPage('ann');
        $bob = $this->openPage('bob');
        $cat = $this->openPage('cat');

        $this->sendOver($bob, 'hello all');

        foreach ([$ann, $bob, $cat] as $client) {
            $this->assertSame(['hello all'], $this->bodiesReceived($client, 1));
        }
    }

    public function testMessageSavedByHttpReachesTheSocket(): void
    {
        $bob = $this->openPage('bob');

        // As the HTTP fallback, the chat worker or MapChat save one
        Chat::sendFrom($this->users['ann'], new MessageText('sent by http'), (int) $this->users['bob']->id);

        $this->assertSame(['sent by http'], $this->bodiesReceived($bob, 1.5));
    }

    public function testSendingAgainAfterALostAnswerGivesTheFirstMessage(): void
    {
        $bob = $this->openPage('bob');
        $before = Chat::getNewestId();

        // The page sends, and the connection drops before the answer arrives
        $lost = $this->connect('ann');
        $lost->hello($before);
        $lost->send(['type' => 'send', 'client_id' => 'lost-answer-1', 'body' => 'only once', 'to' => (int) $this->users['bob']->id]);
        $lost->close();
        usleep(300000);

        // It reconnects from where it was and sends the same message again
        $ann = $this->connect('ann');
        $ann->hello($before);
        $retry = $this->sendOver($ann, 'only once', 'bob', 'lost-answer-1');

        $stmt = $this->db->prepare("SELECT id FROM chats WHERE client_id = 'lost-answer-1'");
        $stmt->execute();
        $this->assertSame([$retry['id']], array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN)));

        // Each page shows it once: the catch-up and the live stream don't both send it
        $this->assertSame(['only once'], $this->bodiesReceived($bob, 1));
        $this->assertSame(['only once'], $this->bodiesReceived($ann, 0.5));
    }

    public function testATranslatedMessageKeepsWhatWasTyped(): void
    {
        $ann = $this->openPage('ann');
        $ann->send(['type' => 'send', 'client_id' => 'translated-1', 'body' => 'Hello', 'to' => null, 'original' => 'হ্যালো']);
        $ann->waitFor('sent', 5, fn ($message) => $message['client_id'] === 'translated-1') ?? $this->fail('No answer to sending');

        $stmt = $this->db->prepare("SELECT body, original_body FROM chats WHERE client_id = 'translated-1'");
        $stmt->execute();
        $this->assertSame(['body' => 'Hello', 'original_body' => 'হ্যালো'], $stmt->fetch(PDO::FETCH_ASSOC));
    }

    public function testRetryIsAnsweredEvenOverTheRateLimit(): void
    {
        $ann = $this->openPage('ann');
        $first = $this->sendOver($ann, 'first try', null, 'retry-limit-1');
        for ($i = 0; $i < Chat::USER_PER_MINUTE; $i++) {
            $this->db->exec("INSERT INTO chats (email, body) VALUES ('{$this->emails['ann']}', 'filler $i')");
        }

        $this->assertSame($first['id'], $this->sendOver($ann, 'first try', null, 'retry-limit-1')['id']);

        $ann->send(['type' => 'send', 'client_id' => 'retry-limit-2', 'body' => 'one too many', 'to' => null]);
        $this->assertSame(429, $ann->waitFor('refused')['status']);
    }

    public function testCatchesUpAfterARestartInOrderAndOnce(): void
    {
        $bob = $this->openPage('bob');
        $seen = Chat::getNewestId();

        self::stopServer();
        // Started again whatever fails here, as the tests after this one share it
        try {
            $this->assertSame(1012, $bob->waitForClose());

            // More than a page arrives while it's down, from both people, some
            // for others. Dated earlier, so they don't count against the rate limit
            $insert = $this->db->prepare('INSERT INTO chats (email, to_email, body, timestamp) VALUES (?, ?, ?, NOW() - INTERVAL 5 MINUTE)');
            for ($i = 1; $i <= Chat::HISTORY * 2 + 50; $i++) {
                $from = $i % 2 ? 'ann' : 'cat';
                $to = $i % 5 === 0 ? 'ann' : 'bob';
                $toEmail = $from === $to ? null : $this->emails[$to];
                $insert->execute([$this->emails[$from], $toEmail, "missed $i"]);
            }
        } finally {
            self::startServer();
        }
        $bob = $this->connect('bob');
        $bob->send(['type' => 'hello', 'after' => $seen]);
        $bob->waitFor('welcome');

        // Messages keep arriving while it catches up
        $ann = $this->connect('ann');
        $ann->hello(Chat::getNewestId());
        for ($i = 1; $i <= 5; $i++) {
            $this->sendOver($ann, "during catch-up $i", 'bob');
        }

        $received = self::chatsIn($bob->collect(3));
        $ids = array_column($received, 0);

        $stmt = $this->db->prepare('SELECT id FROM chats WHERE id > ? AND (to_email IS NULL OR to_email = ? OR email = ?) ORDER BY id');
        $stmt->execute([$seen, $this->emails['bob'], $this->emails['bob']]);
        $expected = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));

        $this->assertGreaterThan(Chat::HISTORY * 2, count($expected));
        $this->assertSame($expected, $ids, 'every message once, in order');
        $this->assertSame('during catch-up 5', end($received)[1]);
    }

    public function testAPageFarBehindSkipsToTheNewestPage(): void
    {
        $seen = Chat::getNewestId();
        $missedCount = Chat::HISTORY * (Hub::CATCH_UP_PAGES + 1) + 50;
        $this->insertRoomMessagesFrom('cat', $missedCount);

        $bob = $this->connect('bob');
        $bob->hello($seen);
        $ids = array_column(self::chatsIn($bob->collect(1)), 0);

        $sortedIds = array_values(array_unique($ids));
        sort($sortedIds);
        $this->assertSame($sortedIds, $ids, 'in order and once');
        $this->assertLessThanOrEqual(Chat::HISTORY * (Hub::CATCH_UP_PAGES + 1), count($ids));
        $this->assertSame(Chat::getNewestId(), end($ids), 'ends with the newest');
    }

    public function testAPageWithNoMessagesYetStartsFromTheNewestPage(): void
    {
        $this->insertRoomMessagesFrom('cat', Chat::HISTORY + 20);

        $bob = $this->connect('bob');
        $bob->hello(0);
        $ids = array_column(self::chatsIn($bob->collect(1)), 0);

        $this->assertCount(Chat::HISTORY, $ids);
        $this->assertSame(Chat::getNewestId(), end($ids));
    }

    public function testOlderPagesPastTheBudgetWaitTheirTurn(): void
    {
        $bob = $this->openPage('bob');
        $extraCount = 2;

        $startedAt = microtime(true);
        for ($requestId = 1; $requestId <= Hub::OLDER_BURST + $extraCount; $requestId++) {
            $bob->send(['type' => 'older', 'request_id' => $requestId, 'before' => Chat::getNewestId() + 1, 'conversation' => 'everyone']);
            $this->assertNotNull($bob->waitFor('older', 5, fn ($message) => $message['request_id'] === $requestId), "older page $requestId");
        }

        // Each one past the burst waited for the budget to refill
        $this->assertGreaterThan(($extraCount - 0.5) / Hub::OLDER_PER_SECOND, microtime(true) - $startedAt);
    }

    public function testHandshakesAtOnceStillKeepToTheTabLimit(): void
    {
        $attemptCount = Handshake::MAX_TABS_PER_USER + 5;
        $streams = [];
        for ($i = 0; $i < $attemptCount; $i++) {
            $stream = stream_socket_client('tcp://' . self::$address, $errorCode, $errorMessage, 5);
            fwrite($stream, implode("\r\n", [
                'GET /ws HTTP/1.1',
                'Host: ' . self::$address,
                'Upgrade: websocket',
                'Connection: Upgrade',
                'Sec-WebSocket-Version: 13',
                'Sec-WebSocket-Key: ' . base64_encode(random_bytes(16)),
                'Origin: http://' . self::$address,
                'Cookie: ' . session_name() . '=' . $this->sessions['ann'],
            ]) . "\r\n\r\n");
            $streams[] = $stream;
        }

        // Every answer, and any close that followed it
        usleep(1500000);
        $openCount = 0;
        foreach ($streams as $stream) {
            stream_set_blocking($stream, false);
            [$head, $frames] = array_pad(explode("\r\n\r\n", (string) stream_get_contents($stream), 2), 2, '');
            $isUpgraded = str_starts_with($head, 'HTTP/1.1 101');
            // 0x88 starts a close frame
            if ($isUpgraded && !str_starts_with($frames, "\x88")) {
                $openCount++;
            }
            fclose($stream);
        }

        $this->assertSame(Handshake::MAX_TABS_PER_USER, $openCount);
    }

    /**
     * Add open-room messages from someone straight to the database, as if
     * sent while a page was away, and wait for the socket to read them all.
     * Dated earlier, so they don't count against the rate limit
     */
    private function insertRoomMessagesFrom(string $name, int $count): void
    {
        $rows = [];
        for ($i = 1; $i <= $count; $i++) {
            $rows[] = [$this->emails[$name], "missed $i"];
        }
        foreach (array_chunk($rows, 500) as $chunk) {
            $placeholders = implode(', ', array_fill(0, count($chunk), '(?, NULL, ?, NOW() - INTERVAL 5 MINUTE)'));
            $this->db->prepare("INSERT INTO chats (email, to_email, body, timestamp) VALUES $placeholders")
                ->execute(array_merge(...$chunk));
        }

        // Nothing signals these, so the socket finds them a batch each round
        $roundCount = intdiv($count, Hub::TAIL_BATCH) + 2;
        usleep((int) ($roundCount * Hub::TAIL_SECONDS * 1000000));
    }

    public function testConcurrentSendersArriveInOrderOnce(): void
    {
        $ann = $this->openPage('ann');
        $cat = $this->openPage('cat');
        $bob = $this->openPage('bob');

        // Both send without waiting for answers, interleaved
        for ($i = 1; $i <= 10; $i++) {
            $ann->send(['type' => 'send', 'client_id' => "concurrent-ann-$i", 'body' => "ann $i", 'to' => (int) $this->users['bob']->id]);
            $cat->send(['type' => 'send', 'client_id' => "concurrent-cat-$i", 'body' => "cat $i", 'to' => (int) $this->users['bob']->id]);
        }

        $received = self::chatsIn($bob->collect(2));
        $ids = array_column($received, 0);
        $sorted = $ids;
        sort($sorted);

        $this->assertCount(20, $received);
        $this->assertSame($sorted, $ids);
        $this->assertSame(array_values(array_unique($ids)), $ids);
    }

    public function testOlderPrivateHistoryComesStraightFromThatChat(): void
    {
        $old = Chat::sendFrom($this->users['ann'], new MessageText('old private'), (int) $this->users['bob']->id)->id;
        for ($i = 0; $i < Chat::HISTORY + 10; $i++) {
            Chat::save($this->emails['cat'], null, new MessageText("busy room $i"));
        }
        $bob = $this->openPage('bob');

        $bob->send(['type' => 'older', 'request_id' => 7, 'before' => Chat::getNewestId() + 1, 'conversation' => (string) $this->users['ann']->id]);
        $older = $bob->waitFor('older', 5, fn ($message) => $message['request_id'] === 7);

        $this->assertSame([[$old, 'old private']], self::chatsIn([['type' => 'chats', 'html' => $older['html']]]));
    }

    public function testReadInOneTabClearsTheOthers(): void
    {
        $ann = $this->openPage('ann');
        $bobFirstTab = $this->openPage('bob');
        $bobSecondTab = $this->openPage('bob');
        $sent = $this->sendOver($ann, 'read me', 'bob');

        $bobFirstTab->send(['type' => 'read', 'conversation' => (string) $this->users['ann']->id, 'up_to' => $sent['id']]);

        $read = $bobSecondTab->waitFor('read');
        $this->assertSame([(string) $this->users['ann']->id, $sent['id']], [$read['conversation'], $read['up_to']]);
        $this->assertNull($ann->waitFor('read', 0.5));
        $this->assertSame(0, (int) Chat::getConversations($this->emails['bob'], PHP_INT_MAX)[0]['unread']);
    }

    public function testEverythingTheSocketSaysMatchesTheAsyncApiSpec(): void
    {
        $ann = $this->openPage('ann');
        $sent = $this->sendOver($ann, 'spec me', 'bob');
        $ann->send(['type' => 'send', 'client_id' => 'too-long-01', 'body' => str_repeat('a', Chat::MAX_LENGTH + 1)]);
        $refused = $ann->waitFor('refused');

        // Said by hand, as SocketClient::hello keeps the chats and live to itself
        $bob = $this->connect('bob');
        $bob->send(['type' => 'hello', 'after' => $sent['id'] - 1]);
        $welcome = $bob->waitFor('welcome');
        $chats = $bob->waitFor('chats');
        $live = $bob->waitFor('live');

        $bobSecondTab = $this->openPage('bob');
        $bob->send(['type' => 'read', 'conversation' => (string) $this->users['ann']->id, 'up_to' => $sent['id']]);
        $read = $bobSecondTab->waitFor('read');

        $ann->send(['type' => 'typing', 'to' => (int) $this->users['bob']->id, 'is_typing' => true]);
        $typing = $bob->waitFor('typing');

        $ann->send(['type' => 'older', 'request_id' => 7, 'before' => $sent['id'] + 1, 'conversation' => 'everyone']);
        $older = $ann->waitFor('older');

        $ann->send(['type' => 'ping']);
        $pong = $ann->waitFor('pong');

        $this->openPage('cat');
        $presence = $ann->waitFor('presence', 5, fn ($message) => $message['person']['id'] === (int) $this->users['cat']->id);

        // Each is checked against the message the spec has for it, by its name there
        $framesBySpecName = [
            'welcome' => $welcome, 'chats' => $chats, 'live' => $live, 'sent' => $sent, 'refused' => $refused,
            'readToPage' => $read, 'typingToPage' => $typing, 'olderPage' => $older, 'pong' => $pong, 'presence' => $presence,
        ];
        foreach ($framesBySpecName as $specName => $frame) {
            $this->assertNotNull($frame, "No $specName frame came");
            // As a page decodes it: objects as objects, which the validator needs
            $asDecoded = json_decode(json_encode($frame));
            $this->assertSame([], ApiSpec::errorsIn($asDecoded, 'asyncapi.yaml', "/components/messages/$specName/payload"), $specName);
        }
    }

    public function testTypingGoesOnlyToThePartnerAndExpires(): void
    {
        $ann = $this->openPage('ann');
        $bob = $this->openPage('bob');
        $cat = $this->openPage('cat');

        $ann->send(['type' => 'typing', 'to' => (int) $this->users['bob']->id, 'is_typing' => true]);

        $typing = $bob->waitFor('typing');
        $this->assertSame([(int) $this->users['ann']->id, true], [$typing['from'], $typing['is_typing']]);
        $this->assertArrayNotHasKey('body', $typing);
        $this->assertNull($cat->waitFor('typing', 0.5));

        // Nothing more from ann: it stops by itself
        $stopped = $bob->waitFor('typing', 8);
        $this->assertFalse($stopped['is_typing']);
    }

    public function testTypingStopsWhenTheTabCloses(): void
    {
        $ann = $this->openPage('ann');
        $bob = $this->openPage('bob');
        $ann->send(['type' => 'typing', 'to' => (int) $this->users['bob']->id, 'is_typing' => true]);
        $bob->waitFor('typing');

        $ann->close();

        $this->assertFalse($bob->waitFor('typing', 2)['is_typing']);
    }

    public function testWorkerTypingReachesOnlyThatPerson(): void
    {
        $ann = $this->openPage('ann');
        $bob = $this->openPage('bob');
        $botId = (int) User::findByEmail(Chat::BOT_EMAIL)->id;

        // As the chat worker does while MapChat writes ann a private reply
        \App\ChatSocket\Signal::typing(Chat::BOT_EMAIL, $this->emails['ann'], true);

        $typing = $ann->waitFor('typing');
        $this->assertSame([$botId, true], [$typing['from'], $typing['is_typing']]);
        $this->assertNull($bob->waitFor('typing', 0.5));
    }

    public function testPersonStaysOnlineWhileAnyTabIsOpen(): void
    {
        $cat = $this->openPage('cat');
        $annId = (int) $this->users['ann']->id;
        $isAboutAnn = fn ($message) => (int) $message['person']['id'] === $annId;

        $firstTab = $this->openPage('ann');
        $secondTab = $this->openPage('ann');
        $this->assertTrue($cat->waitFor('presence', 3, $isAboutAnn)['person']['is_online']);

        $firstTab->close();
        $this->assertNull($cat->waitFor('presence', self::GRACE_SECONDS + 1.5, $isAboutAnn), 'still online in the other tab');

        $secondTab->close();
        // A reload within the grace period wouldn't show them leaving; past it, they've gone
        $this->assertFalse($cat->waitFor('presence', self::GRACE_SECONDS + 6, $isAboutAnn)['person']['is_online']);
    }

    public function testWelcomeListsWhoIsOnlineWithoutEmails(): void
    {
        $this->openPage('ann');
        $bob = $this->connect('bob');
        $welcome = $bob->hello(0);

        $ann = array_values(array_filter($welcome['people'], fn ($person) => (int) $person['id'] === (int) $this->users['ann']->id));
        $this->assertTrue($ann[0]['is_online']);
        $this->assertStringNotContainsString('@socket.test', json_encode($welcome));
    }

    public function testLoggingOutClosesTheTab(): void
    {
        $ann = $this->openPage('ann');

        $this->db->prepare('DELETE FROM sessions WHERE id = ?')->execute([$this->sessions['ann']]);

        $this->assertSame(4401, $ann->waitForClose(self::SESSION_CHECK_SECONDS + 3));
    }

    public function testAPasswordChangedElsewhereClosesTheTabAndRefusesItsSession(): void
    {
        $ann = $this->openPage('ann');

        $this->db->prepare('UPDATE users SET password_hash = ? WHERE email = ?')
            ->execute([password_hash('a new password', PASSWORD_DEFAULT), $this->emails['ann']]);

        $this->assertSame(4401, $ann->waitForClose(self::SESSION_CHECK_SECONDS + 3));
        $this->assertSame(401, $this->connect('ann')->status);
    }

    public function testRefusesOtherSitesAndMissingSessions(): void
    {
        $this->assertSame(403, $this->connect('ann', 'https://evil.example')->status);
        $this->assertSame(401, (new SocketClient(self::$address, null))->status);
        $this->assertSame(401, (new SocketClient(self::$address, 'made-up-session-id-1234567890'))->status);
    }

    public function testClosesTabsThatSendMalformedMessages(): void
    {
        $notJson = $this->openPage('ann');
        $notJson->sendText('not json');
        $this->assertSame(1008, $notJson->waitForClose());

        $tooBig = $this->openPage('bob');
        $tooBig->sendText(json_encode(['type' => 'ping', 'padding' => str_repeat('x', 20000)]));
        $this->assertSame(1009, $tooBig->waitForClose());

        $beforeHello = $this->connect('cat');
        $beforeHello->send(['type' => 'send', 'client_id' => 'early-1', 'body' => 'hi', 'to' => null]);
        $this->assertSame(1008, $beforeHello->waitForClose());
    }
}

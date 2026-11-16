<?php

namespace App\Models;

use PDO;
use App\ChatSocket\Signal;
use App\Groq;
use App\Mention;

/**
 * The MapChat account chats back: it greets a visitor who walks into a quiet
 * room, answers someone nobody else answered, keeps talking with whoever it's
 * talking to, and answers private messages. A demo person someone @mentions
 * answers too, in character, and keeps talking with them the same way, and
 * so does one someone messages privately.
 * Each of these is a job in mapchat_jobs (BotJob), made when someone opens
 * the chat or sends a message. The chat worker (bin/chat-worker.php) runs
 * the jobs that are due each second, and each job checks the room again
 * before it posts. What they're told is BotInstructions; Groq writes them
 */
class Bot extends \Core\Model
{
    /** The room counts as quiet when no person has spoken in it for this long */
    const QUIET_MINUTES = 10;

    /** A visitor to a quiet room gets a chance to speak first, then a greeting */
    const GREET_SECONDS = 20;

    /** One greeting per person this often, however many times they open the chat */
    const GREET_COOLDOWN_HOURS = 24;

    /**
     * How long someone else online has to answer a visitor before MapChat
     * does. With nobody else online it answers as soon as a follow-up
     */
    const GRACE_SECONDS = 45;

    /** How soon MapChat answers someone it's already talking to, or who @mentions it */
    const FOLLOW_UP_SECONDS = 3;

    /** Its name, as people @mention it */
    const NAME = 'MapChat';

    /** A conversation with MapChat ends when its last message is older than this */
    const CONVERSATION_MINUTES = 5;

    /** At most one answer in the open room this often, MapChat's or a demo person's */
    const ROOM_COOLDOWN_SECONDS = 20;

    /** How soon MapChat answers a private message */
    const PRIVATE_SECONDS = 2;

    /** How long a demo person who's away takes to come online when called or messaged */
    const COME_ONLINE_SECONDS = 6;

    /** How long a demo person takes to answer once they're online */
    const DEMO_ANSWER_SECONDS = 5;

    /**
     * A private reply shows "typing" about as long as a person would take
     * to type it, writing included, within these bounds
     */
    const TYPING_CHARS_PER_SECOND = 5;
    const FEWEST_TYPING_SECONDS = 2;
    const MOST_TYPING_SECONDS = 10;

    /** At most this many people answer one message that @mentions several */
    const MOST_ASKED = 2;

    /** How many earlier messages MapChat reads before it answers */
    const CONTEXT_MESSAGES = 20;

    /**
     * Writes the replies in place of Groq, for the tests
     *
     * @var (\Closure(string $instructions, string $chat): ?string)|null
     */
    protected static ?\Closure $writer = null;

    /**
     * Whether MapChat can write replies, or only greet
     */
    public static function canReply(): bool
    {
        return static::$writer !== null || Groq::isEnabled();
    }

    /**
     * Someone opened the chat. If the room is quiet, greet them in a moment,
     * unless they speak first. Every tab and reload calls this; only the
     * first in GREET_COOLDOWN_HOURS makes a greeting
     */
    public static function onArrival(string $email): void
    {
        if (!static::isPerson($email) || !static::isQuiet($email) || static::wasGreetedLately($email)) {
            return;
        }

        // Two tabs opening at once make the same key, so only one gets in
        $period = intdiv(static::now(), static::GREET_COOLDOWN_HOURS * 3600);
        static::schedule(BotJobKind::Greeting, $email, null, "greet:$email:$period", static::GREET_SECONDS);
    }

    /**
     * Someone sent a message. Decide whether MapChat, or a demo person,
     * should answer it
     *
     * @param string $email The sender
     * @param int $chatId Their message
     * @param string|null $toEmail Who it's for; null for the open room
     * @param string $body What they wrote
     */
    public static function onMessage(string $email, int $chatId, ?string $toEmail, string $body): void
    {
        // MapChat's own messages and the demo people's never start a job
        if (!static::isPerson($email)) {
            return;
        }

        // A private message to MapChat or a demo person gets an answer there;
        // one to anyone else is between them
        if ($toEmail === Chat::BOT_EMAIL) {
            static::schedule(BotJobKind::PrivateReply, $email, $chatId, "dm:$chatId", static::PRIVATE_SECONDS, $toEmail);
            return;
        }
        if ($toEmail !== null && DemoChat::isDemoEmail($toEmail)) {
            static::schedule(BotJobKind::PrivateReply, $email, $chatId, "dm:$chatId", static::demoAnswerDelay($toEmail), $toEmail);
            return;
        }
        if ($toEmail !== null) {
            return;
        }

        // They spoke first: answer that rather than greet them as well
        static::getDB()->prepare('UPDATE mapchat_jobs SET done_at = :now
                                  WHERE kind = :greeting AND email = :email AND done_at IS NULL')
            ->execute([':now' => static::time(), ':greeting' => BotJobKind::Greeting->value, ':email' => $email]);

        // Asked by name: whoever was asked answers, however busy the room is
        $asked = static::askedIn($body);
        foreach ($asked as $speaker) {
            $delay = $speaker === Chat::BOT_EMAIL ? static::FOLLOW_UP_SECONDS : static::demoAnswerDelay($speaker);
            static::schedule(BotJobKind::Mention, $email, $chatId, "mention:$chatId:$speaker", $delay, $speaker);
        }
        if ($asked) {
            return;
        }

        $partner = static::talkingWith($email, $chatId);
        if ($partner !== null) {
            static::schedule(BotJobKind::Reply, $email, $chatId, "reply:$chatId", static::FOLLOW_UP_SECONDS, $partner);
        } elseif (static::isQuiet($email, $chatId)) {
            $grace = static::isAnyoneElseOnline($email) ? static::GRACE_SECONDS : static::FOLLOW_UP_SECONDS;
            static::schedule(BotJobKind::Reply, $email, $chatId, "reply:$chatId", $grace);
        }
    }

    /**
     * Run the jobs that are due. The chat worker calls this every second,
     * and so do chat pages on the HTTP fallback, so only one at a time does
     * it; the rest skip it
     */
    public static function runDue(): void
    {
        $db = static::getDB();

        if (!$db->query("SELECT GET_LOCK('mapchat_jobs', 0)")->fetchColumn()) {
            return;
        }

        try {
            // Replies still being "typed": the page lets "typing" lapse
            // after a few seconds without a fresh word
            $typing = $db->query('SELECT speaker, email FROM mapchat_jobs WHERE done_at IS NULL AND reply IS NOT NULL');
            foreach ($typing->fetchAll(PDO::FETCH_ASSOC) as $job) {
                Signal::typing($job['speaker'], $job['email'], true);
            }

            $stmt = $db->prepare('SELECT * FROM mapchat_jobs WHERE done_at IS NULL AND due_at <= :now ORDER BY due_at, id');
            $stmt->execute([':now' => static::time()]);

            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                static::run(BotJob::fromRow($row));
            }
        } catch (\Exception $e) {
            error_log('MapChat jobs failed, the next round tries again: ' . $e->getMessage());
        } finally {
            $db->query("SELECT RELEASE_LOCK('mapchat_jobs')");
        }
    }

    /**
     * Do one job, or put a room answer off while the room had one just now
     */
    protected static function run(BotJob $job): void
    {
        $wait = $job->kind->isPrivate() ? 0 : static::roomCooldownLeft();
        if ($wait > 0) {
            static::setDue($job, $wait);
            return;
        }

        // Marked done before it runs, so a failed reply isn't tried again every second
        if (!static::claim($job)) {
            return;
        }

        if ($job->kind->isPrivate()) {
            static::answerPrivately($job);
        } else {
            static::answerInRoom($job);
        }
    }

    /**
     * Post a job's greeting or answer in the open room, tagged with who
     * it's for where that's needed
     */
    protected static function answerInRoom(BotJob $job): void
    {
        $body = $job->kind === BotJobKind::Greeting ? static::greeting($job) : static::publicReply($job);
        if ($body === null) {
            return;
        }

        if (static::needsTag($job)) {
            $body = static::tag($job->email, $body);
        }
        static::post($job, static::fitLength($body), null);
    }

    /**
     * Answer in a private chat. The speaker is seen typing while the reply
     * is written, and after, until it would have taken to type, so the
     * reply waits on the job until then. The reply itself ends the typing;
     * it's only ended here if none comes
     */
    protected static function answerPrivately(BotJob $job): void
    {
        // A reply written earlier, now typed
        if ($job->reply !== null) {
            static::post($job, $job->reply, $job->email);
            return;
        }

        Signal::typing($job->speaker, $job->email, true);
        $startedAt = microtime(true);

        try {
            $body = static::privateReply($job);
        } catch (\Throwable $e) {
            Signal::typing($job->speaker, $job->email, false);
            throw $e;
        }

        if ($body === null) {
            Signal::typing($job->speaker, $job->email, false);
            return;
        }

        $body = static::fitLength($body);
        $typingLeft = (int) ceil(static::typingSeconds($body) - (microtime(true) - $startedAt));
        if ($typingLeft <= 0) {
            static::post($job, $body, $job->email);
            return;
        }

        // Due again once typed; runDue keeps "typing" showing till then
        static::getDB()->prepare('UPDATE mapchat_jobs SET reply = :reply, due_at = :due, done_at = NULL WHERE id = :id')
            ->execute([':reply' => $body, ':due' => static::time($typingLeft), ':id' => $job->id]);
    }

    /**
     * Put a job off for a number of seconds
     */
    protected static function setDue(BotJob $job, int $seconds): void
    {
        static::getDB()->prepare('UPDATE mapchat_jobs SET due_at = :due WHERE id = :id')
            ->execute([':due' => static::time($seconds), ':id' => $job->id]);
    }

    /**
     * Whether a room message needs an @mention to show who it's for: a
     * greeting or a digital character's answer always, MapChat's answer only
     * when other messages came in between
     */
    protected static function needsTag(BotJob $job): bool
    {
        if ($job->kind === BotJobKind::Greeting || $job->speaker !== Chat::BOT_EMAIL) {
            return true;
        }

        $stmt = static::getDB()->prepare('SELECT COUNT(*) FROM chats WHERE to_email IS NULL AND id > :id');
        $stmt->execute([':id' => $job->chatId]);

        return $stmt->fetchColumn() > 0;
    }

    /**
     * Mark a job done, unless another worker or page got to it first
     *
     * @return bool Whether this one has it
     */
    protected static function claim(BotJob $job): bool
    {
        $stmt = static::getDB()->prepare('UPDATE mapchat_jobs SET done_at = :now WHERE id = :id AND done_at IS NULL');
        $stmt->execute([':now' => static::time(), ':id' => $job->id]);

        return $stmt->rowCount() > 0;
    }

    /**
     * Send the speaker's message and note it on the job
     *
     * @param string|null $toEmail Null for the open room
     */
    protected static function post(BotJob $job, string $body, ?string $toEmail): void
    {
        $postedId = Chat::save($job->speaker, $toEmail, $body);

        static::getDB()->prepare('UPDATE mapchat_jobs SET posted_id = :posted WHERE id = :id')
            ->execute([':posted' => $postedId, ':id' => $job->id]);

        // A demo person who just spoke is in the room
        UserInfo::markOnline($job->speaker);
    }

    /**
     * A short hello, if the room is still quiet
     */
    protected static function greeting(BotJob $job): ?string
    {
        // If they spoke first, onMessage() already closed this job
        if (!static::isQuiet($job->email)) {
            return null;
        }

        $person = static::person($job->email);
        if (!$person) {
            return null;
        }

        // answerInRoom() tags them with their name, so it's left out here
        $greetings = $person['city']
            ? ["Welcome! 👋 How's the weather in {$person['city']} today?",
               "Hey! 👋 It's quiet in here right now. What's {$person['city']} like this time of day?"]
            : ["Welcome! 👋 Where in the world are you chatting from?",
               "Hey! 👋 It's quiet in here right now. What brings you to MapChat?"];

        return $greetings[crc32($job->email) % count($greetings)];
    }

    /**
     * An answer in the open room, written only from what's in the open room,
     * unless the person has said more since, or nobody asked for it and
     * someone else answered first
     */
    protected static function publicReply(BotJob $job): ?string
    {
        $wasAsked = $job->kind === BotJobKind::Mention;
        if (static::hasNewerJob($job) || (!$wasAsked && static::someoneAnswered($job->email, $job->chatId))) {
            return null;
        }

        $name = static::shownNameOf($job->email);

        return static::writtenReply(
            static::instructionsFor($job)
                . "\n\nYou're in the open room, which everyone can read. Reply to $name's latest message."
                . BotInstructions::closingNote(static::bodyOf($job->chatId)),
            static::roomHistory($job->chatId, $job->speaker),
            $job->speaker
        );
    }

    /**
     * An answer in the private chat, written only from that chat
     */
    protected static function privateReply(BotJob $job): ?string
    {
        if (static::hasNewerJob($job)) {
            return null;
        }

        $name = static::shownNameOf($job->email);

        return static::writtenReply(
            static::instructionsFor($job)
                . "\n\nThis is your private chat with $name; nobody else can read it. Reply to their latest message."
                . BotInstructions::closingNote(static::bodyOf($job->chatId)),
            static::privateHistory($job->email, $job->speaker, $job->chatId),
            $job->speaker
        );
    }

    /**
     * Who the job's speaker is and how they write
     */
    protected static function instructionsFor(BotJob $job): string
    {
        $speakerPerson = $job->speaker === Chat::BOT_EMAIL ? null : static::person($job->speaker);

        return BotInstructions::forSpeaker($job->speaker, $speakerPerson ?: null);
    }

    /**
     * What a message says
     */
    protected static function bodyOf(int $chatId): string
    {
        $stmt = static::getDB()->prepare('SELECT body FROM chats WHERE id = :id');
        $stmt->execute([':id' => $chatId]);

        return (string) $stmt->fetchColumn();
    }

    /**
     * The open room's messages up to this one, oldest first. MapChat leaves
     * the demo people's script out; a demo person reads all of it, so they
     * know the scene they were summoned in the middle of
     *
     * @param string $speaker Who's answering
     *
     * @return array<array{name: string, nickname: ?string, is_guest: string, email: string, body: string}>
     */
    protected static function roomHistory(int $upToId, string $speaker): array
    {
        $script = $speaker === Chat::BOT_EMAIL ? static::notDemoSql() : '';
        $stmt = static::getDB()->prepare('SELECT users.name, users.nickname, users.is_guest, chats.email, chats.body FROM chats
                                          JOIN users ON chats.email = users.email
                                          WHERE chats.to_email IS NULL AND chats.id <= :id' . $script . '
                                          ORDER BY chats.id DESC LIMIT ' . static::CONTEXT_MESSAGES);
        $stmt->execute([':id' => $upToId]);

        return array_reverse($stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * One person's private chat with the speaker (MapChat or a demo person)
     * up to this message, oldest first
     *
     * @return array<array{name: string, nickname: ?string, is_guest: string, email: string, body: string}>
     */
    protected static function privateHistory(string $email, string $speaker, int $upToId): array
    {
        $stmt = static::getDB()->prepare('SELECT users.name, users.nickname, users.is_guest, chats.email, chats.body FROM chats
                                          JOIN users ON chats.email = users.email
                                          WHERE ((chats.email = :person AND chats.to_email = :speaker)
                                              OR (chats.email = :speaker2 AND chats.to_email = :person2))
                                            AND chats.id <= :id
                                          ORDER BY chats.id DESC LIMIT ' . static::CONTEXT_MESSAGES);
        $stmt->execute([
            ':person' => $email, ':speaker' => $speaker,
            ':speaker2' => $speaker, ':person2' => $email, ':id' => $upToId,
        ]);

        return array_reverse($stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * The reply the writer gives, from the chat as a transcript with the
     * speaker's own lines marked "(you)", tidied (tidy)
     *
     * @param array<array{name: string, nickname: ?string, is_guest: string, email: string, body: string}> $messages Oldest first
     * @param string $speaker Who's answering
     *
     * @return string|null Null when nothing could be written
     */
    protected static function writtenReply(string $instructions, array $messages, string $speaker): ?string
    {
        if (!static::canReply()) {
            return null;
        }

        $lines = array_map(function ($message) use ($speaker) {
            $name = Mention::shownName($message['name'], $message['nickname'], (bool) $message['is_guest']);
            return $name . ($message['email'] === $speaker ? ' (you)' : '') . ': ' . $message['body'];
        }, $messages);

        $writer = static::$writer ?? Groq::write(...);
        $reply = $writer($instructions, implode("\n", $lines));

        if ($reply === null || trim($reply) === '') {
            return null;
        }

        return static::tidy($reply);
    }

    /**
     * Add a job, unless one with the same key is already there
     *
     * @param string $email Who it's for
     * @param int|null $chatId Their message, if it's an answer
     * @param string $key Makes the same job made twice at once one job
     * @param int $delay Seconds until it's due
     * @param string $speaker Who answers: MapChat, or a demo person
     */
    protected static function schedule(BotJobKind $kind, string $email, ?int $chatId, string $key, int $delay, string $speaker = Chat::BOT_EMAIL): void
    {
        static::getDB()->prepare('INSERT IGNORE INTO mapchat_jobs (kind, email, speaker, chat_id, dedupe_key, due_at, created_at)
                                  VALUES (:kind, :email, :speaker, :chat_id, :key, :due, :now)')
            ->execute([
                ':kind' => $kind->value, ':email' => $email, ':speaker' => $speaker, ':chat_id' => $chatId, ':key' => $key,
                ':due' => static::time($delay), ':now' => static::time(),
            ]);
    }

    /**
     * Who the message asks by name: MapChat and any demo people, a couple
     * at most
     *
     * @return string[] Their emails
     */
    protected static function askedIn(string $body): array
    {
        $asked = Mention::isMentionedIn($body, static::NAME) ? [Chat::BOT_EMAIL] : [];

        $stmt = static::getDB()->query('SELECT email, name, nickname FROM users WHERE ' . DemoChat::isDemoSql('email'));
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $demoPerson) {
            if (Mention::isMentionedIn($body, $demoPerson['name'], $demoPerson['nickname'])) {
                $asked[] = $demoPerson['email'];
            }
        }

        return array_slice($asked, 0, static::MOST_ASKED);
    }

    /**
     * Whether this person got a greeting, or was about to, within the cooldown
     */
    protected static function wasGreetedLately(string $email): bool
    {
        $stmt = static::getDB()->prepare('SELECT COUNT(*) FROM mapchat_jobs
                                          WHERE kind = :greeting AND email = :email AND created_at > :since');
        $stmt->execute([
            ':greeting' => BotJobKind::Greeting->value,
            ':email' => $email,
            ':since' => static::time(-static::GREET_COOLDOWN_HOURS * 3600),
        ]);

        return $stmt->fetchColumn() > 0;
    }

    /**
     * Who's talking with this person: whoever had the last answering word
     * in the open room (MapChat or a demo person), if it was to them, a
     * little while ago, and no other person has spoken since
     *
     * @param int $chatId Their new message
     *
     * @return string|null The speaker's email
     */
    protected static function talkingWith(string $email, int $chatId): ?string
    {
        $last = static::lastRoomPost();

        if (!$last || $last['email'] !== $email || $last['done_at'] < static::time(-static::CONVERSATION_MINUTES * 60)) {
            return null;
        }

        $stmt = static::getDB()->prepare('SELECT COUNT(*) FROM chats
                                          WHERE to_email IS NULL AND id > :after AND id < :before AND email <> :email'
                                          . static::peopleOnlySql());
        $stmt->execute([':after' => $last['posted_id'], ':before' => $chatId, ':email' => $email]);

        return $stmt->fetchColumn() == 0 ? $last['speaker'] : null;
    }

    /**
     * Whether nobody but this person has spoken in the open room lately
     *
     * @param int|null $beforeId Only look before this message
     */
    protected static function isQuiet(string $email, ?int $beforeId = null): bool
    {
        $stmt = static::getDB()->prepare('SELECT COUNT(*) FROM chats
                                          WHERE to_email IS NULL AND email <> :email AND timestamp > :since AND id < :before_id'
                                          . static::peopleOnlySql());
        $stmt->execute([
            ':email' => $email,
            ':since' => static::time(-static::QUIET_MINUTES * 60),
            ':before_id' => $beforeId ?? PHP_INT_MAX,
        ]);

        return $stmt->fetchColumn() == 0;
    }

    /**
     * Whether another person has the chat open, who could answer instead
     */
    protected static function isAnyoneElseOnline(string $email): bool
    {
        $stmt = static::getDB()->prepare('SELECT COUNT(*) FROM userinfo
                                          WHERE email <> :email AND online_at > :since'
                                          . static::peopleOnlySql('userinfo.email'));
        $stmt->execute([':email' => $email, ':since' => static::time(-UserInfo::ONLINE_SECONDS)]);

        return $stmt->fetchColumn() > 0;
    }

    /**
     * Whether another person spoke in the open room after this message
     */
    protected static function someoneAnswered(string $email, int $chatId): bool
    {
        $stmt = static::getDB()->prepare('SELECT COUNT(*) FROM chats
                                          WHERE to_email IS NULL AND id > :id AND email <> :email'
                                          . static::peopleOnlySql());
        $stmt->execute([':id' => $chatId, ':email' => $email]);

        return $stmt->fetchColumn() > 0;
    }

    /**
     * Whether the person said more since, with its own job to answer it all
     */
    protected static function hasNewerJob(BotJob $job): bool
    {
        $stmt = static::getDB()->prepare('SELECT COUNT(*) FROM mapchat_jobs
                                          WHERE kind = :kind AND email = :email AND speaker = :speaker
                                            AND chat_id > :chat_id AND done_at IS NULL');
        $stmt->execute([
            ':kind' => $job->kind->value, ':email' => $job->email, ':speaker' => $job->speaker, ':chat_id' => $job->chatId,
        ]);

        return $stmt->fetchColumn() > 0;
    }

    /**
     * Seconds until MapChat may post in the open room again
     */
    protected static function roomCooldownLeft(): int
    {
        $last = static::lastRoomPost();

        if (!$last) {
            return 0;
        }

        return max(0, strtotime($last['done_at'] . ' UTC') + static::ROOM_COOLDOWN_SECONDS - static::now());
    }

    /**
     * The job behind the latest answer in the open room, MapChat's or a
     * demo person's
     *
     * @return array{email: string, speaker: string, posted_id: string, done_at: string}|null Who it was to, who said it, and when
     */
    protected static function lastRoomPost(): ?array
    {
        $stmt = static::getDB()->prepare('SELECT email, speaker, posted_id, done_at FROM mapchat_jobs
                                          WHERE kind <> :private AND posted_id IS NOT NULL
                                          ORDER BY posted_id DESC LIMIT 1');
        $stmt->execute([':private' => BotJobKind::PrivateReply->value]);

        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /**
     * The demo people talking with someone now: from COME_ONLINE_SECONDS
     * after they were called or messaged until CONVERSATION_MINUTES after
     * their last answer, so they stay online between answers
     *
     * @return string[] Their emails
     */
    public static function talkingDemoPeople(): array
    {
        $stmt = static::getDB()->prepare('SELECT DISTINCT speaker FROM mapchat_jobs
                                          WHERE ' . DemoChat::isDemoSql('speaker') . '
                                            AND ((done_at IS NULL AND created_at <= :arrived) OR done_at > :since)');
        $stmt->execute([
            ':arrived' => static::time(-static::COME_ONLINE_SECONDS),
            ':since' => static::time(-static::CONVERSATION_MINUTES * 60),
        ]);

        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    /**
     * How soon a demo person answers: a moment to type, after a moment to
     * come online if they're away
     *
     * @return int Seconds
     */
    protected static function demoAnswerDelay(string $speaker): int
    {
        $isHere = in_array($speaker, array_merge(DemoChat::presentNow(), static::talkingDemoPeople()), true);

        return ($isHere ? 0 : static::COME_ONLINE_SECONDS) + static::DEMO_ANSWER_SECONDS;
    }

    /**
     * How long the demo story has waited since the given time: from each
     * time a guest called a demo person into a room conversation until it
     * went quiet, CONVERSATION_MINUTES after their last answer. Times that
     * overlap count once
     *
     * @param int $since Unix time, the start of the demo day
     *
     * @return int Seconds
     */
    public static function storyPausedSeconds(int $since): int
    {
        $stmt = static::getDB()->prepare('SELECT created_at, done_at FROM mapchat_jobs
                                          WHERE kind IN (:mention, :reply) AND ' . DemoChat::isDemoSql('speaker') . ' AND created_at >= :since
                                          ORDER BY created_at');
        $stmt->execute([
            ':mention' => BotJobKind::Mention->value,
            ':reply' => BotJobKind::Reply->value,
            ':since' => gmdate('Y-m-d H:i:s', $since),
        ]);

        $now = static::now();
        $waited = 0;
        $from = null;
        $until = null;
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $job) {
            $start = strtotime($job['created_at'] . ' UTC');
            // Still to answer: waiting until now
            $end = $job['done_at'] ? strtotime($job['done_at'] . ' UTC') + static::CONVERSATION_MINUTES * 60 : $now;
            $end = min($end, $now);

            if ($from !== null && $start <= $until) {
                $until = max($until, $end);
                continue;
            }
            $waited += $from === null ? 0 : $until - $from;
            [$from, $until] = [$start, $end];
        }

        return $waited + ($from === null ? 0 : max(0, $until - $from));
    }

    /**
     * Whether this is a real person: not MapChat and not one of the demo people
     */
    protected static function isPerson(string $email): bool
    {
        return $email !== Chat::BOT_EMAIL && !DemoChat::isDemoEmail($email);
    }

    /**
     * Leaves MapChat and the demo people out of a query
     *
     * @param string $column The column holding each row's email
     */
    protected static function peopleOnlySql(string $column = 'chats.email'): string
    {
        return " AND $column <> '" . Chat::BOT_EMAIL . "'" . static::notDemoSql($column);
    }

    /**
     * Leaves the demo people out of a query
     *
     * @param string $column The column holding each row's email
     */
    protected static function notDemoSql(string $column = 'chats.email'): string
    {
        return ' AND NOT ' . DemoChat::isDemoSql($column);
    }

    /**
     * How someone is shown and @mentioned (Mention::shownName), or "them"
     */
    protected static function shownNameOf(string $email): string
    {
        $person = static::person($email);

        return $person ? Mention::shownName($person['name'], $person['nickname'], (bool) $person['is_guest']) : 'them';
    }

    /**
     * Name, nickname, whether they're a guest, and, if they share it, city
     *
     * @return array{name: string, nickname: ?string, is_guest: string, city: ?string}|null
     */
    protected static function person(string $email): ?array
    {
        $stmt = static::getDB()->prepare('SELECT users.name, users.nickname, users.is_guest, IF(users.share_location, userinfo.city, NULL) AS city
                                          FROM users LEFT JOIN userinfo ON users.email = userinfo.email
                                          WHERE users.email = :email');
        $stmt->execute([':email' => $email]);

        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /**
     * Swap dashes the model wrote anyway for commas, and drop an opening
     * self-introduction
     */
    protected static function tidy(string $body): string
    {
        $body = trim(preg_replace('/\s*[—–]\s*/u', ', ', $body));

        // An opening "MapChat here, ..." just repeats the name shown above it
        return preg_replace('/^MapChat here\b[^.!?]*[.!?]\s*/iu', '', $body) ?: $body;
    }

    /**
     * The message starting with an @mention of the person it's for, unless
     * it already mentions them
     */
    protected static function tag(string $email, string $body): string
    {
        $person = static::person($email);
        if (!$person) {
            return $body;
        }

        $isGuest = (bool) $person['is_guest'];
        if (Mention::isMentionedIn($body, $person['name'], $person['nickname'], $isGuest)) {
            return $body;
        }

        return '@' . Mention::shownName($person['name'], $person['nickname'], $isGuest) . ' ' . $body;
    }

    /**
     * The message cut to the length people are held to
     */
    protected static function fitLength(string $body): string
    {
        return mb_strlen($body) > Chat::MAX_LENGTH ? rtrim(mb_substr($body, 0, Chat::MAX_LENGTH - 1)) . '…' : $body;
    }

    /**
     * How long a person would take to type a message, within bounds
     *
     * @return float Seconds
     */
    protected static function typingSeconds(string $body): float
    {
        $seconds = mb_strlen($body) / static::TYPING_CHARS_PER_SECOND;

        return min(static::MOST_TYPING_SECONDS, max(static::FEWEST_TYPING_SECONDS, $seconds));
    }

    /**
     * The time now, as Unix time. Tests move it forward
     */
    protected static function now(): int
    {
        return time();
    }

    /**
     * The time a number of seconds from now, as the database stores it
     *
     * @return string UTC, Y-m-d H:i:s
     */
    protected static function time(int $seconds = 0): string
    {
        return gmdate('Y-m-d H:i:s', static::now() + $seconds);
    }
}

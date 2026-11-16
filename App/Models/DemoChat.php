<?php

namespace App\Models;

use PDO;
use App\Config;

/**
 * The demo people talk in the room through the day: a few running stories
 * and one-off chats (database/demo-chat.php) at a slow pace, in a fresh
 * order each day. There's no timer on the server (the free plan sleeps), so
 * catchUp() adds the messages that are due by now: the chat worker runs it
 * every minute, and so do chat pages on the HTTP fallback. Earlier days'
 * are cleared, and the day follows DEMO_TIMEZONE. While a guest is talking
 * with a demo person they called into the room, the story waits
 * (Bot::storyPausedSeconds) and goes on from where it was
 */
class DemoChat extends \Core\Model
{
    /** The demo people's email addresses end with this */
    const DOMAIN = '@demo.mapchat.local';

    /** The demo day starts at midnight here unless DEMO_TIMEZONE says otherwise */
    const TIMEZONE = 'America/Toronto';

    /**
     * How fast the demo people read and type, in characters a minute. Slower
     * than real people on purpose, so the room stays relaxed
     */
    const READ_SPEED = 60;
    const TYPE_SPEED = 30;

    /**
     * The scenes that open each day, in this order, right after the bot's
     * welcome: they talk about the map and pins it has just explained
     */
    const OPENING = ['app_map', 'app_bot'];

    /**
     * Leaves out the demo people's answers to someone who @mentioned them
     * (Bot), which are written live, not from the day's script
     */
    const NOT_REPLIES = 'id NOT IN (SELECT posted_id FROM mapchat_jobs WHERE posted_id IS NOT NULL)';

    /** A demo person shows as online from this many minutes before they speak... */
    const ARRIVE_MINUTES = 5;

    /** ...until this many minutes after */
    const LINGER_MINUTES = 30;

    /** At most this many of a demo person's stories go to the writer, the latest ones */
    const MOST_STORIES = 6;

    /** Catching up more often than this does nothing new for a slow conversation, in seconds */
    const CATCH_UP_SECONDS = 60;

    /**
     * Whether this email is one of the demo people's
     */
    public static function isDemoEmail(string $email): bool
    {
        return str_ends_with($email, static::DOMAIN);
    }

    /**
     * SQL that's true for the demo people
     *
     * @param string $column The column holding each row's email
     */
    public static function isDemoSql(string $column = 'users.email'): string
    {
        return "$column LIKE '%" . static::DOMAIN . "'";
    }

    /**
     * Whether it's time to catch up again, CATCH_UP_SECONDS after the last
     * time, or always while DEMO_SPEED fast-forwards the day
     *
     * @param int $lastCaughtUpAt Unix time, or 0 for never
     */
    public static function isCatchUpDue(int $lastCaughtUpAt): bool
    {
        return static::isFast() || time() - $lastCaughtUpAt >= static::CATCH_UP_SECONDS;
    }

    /**
     * Add any demo messages that are due, and clear earlier days'. The
     * worker and pages on the HTTP fallback all ask for this, so only one
     * does it at a time; the rest skip it. Its changes are saved together or
     * not at all, so a failure part way can't leave a gap: the chat runs in
     * id order, and later lines are only ever added after the last one there
     */
    public static function catchUp(): void
    {
        $db = static::getDB();

        if (!$db->query("SELECT GET_LOCK('demo_catch_up', 0)")->fetchColumn()) {
            return;
        }

        try {
            $db->beginTransaction();
            static::catchUpLocked($db);
            $db->commit();
        } catch (\PDOException $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            error_log('Demo catch-up failed, the next request tries again: ' . $e->getMessage());
        } finally {
            $db->query("SELECT RELEASE_LOCK('demo_catch_up')");
        }
    }

    /**
     * catchUp()'s work, while it holds the lock
     */
    protected static function catchUpLocked(PDO $db): void
    {
        $today = static::today();
        $dayStart = static::dayStart($today);
        $schedule = static::schedule($today);

        static::moveWelcomeToToday($db, $dayStart);
        $postedUpTo = static::clearUnplannedLines($db, $schedule, $dayStart);
        $lastSpokeAt = static::postDueLines($db, $schedule, $postedUpTo, $dayStart);
        static::markSeen($db, $lastSpokeAt);
    }

    /**
     * Add the welcome messages again after older messages, so they open each
     * day and are never under "Yesterday". The chat is in id order, so
     * they're deleted and added anew. What MapChat said to people
     * (mapchat_jobs) stays where it was said
     *
     * @param int $dayStart Unix time
     */
    protected static function moveWelcomeToToday(PDO $db, int $dayStart): void
    {
        $stmt = $db->prepare('SELECT id, body FROM chats WHERE email = :bot AND to_email IS NULL AND timestamp < :start
                                AND ' . static::NOT_REPLIES . ' ORDER BY id');
        $stmt->execute([':bot' => Chat::BOT_EMAIL, ':start' => gmdate('Y-m-d H:i:s', $dayStart)]);

        $add = $db->prepare('INSERT INTO chats (email, body, timestamp) VALUES (:email, :body, :time)');
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $i => $welcome) {
            // Unless another request at the same moment already moved it
            if (static::deleteLine($db, (int) $welcome['id'])) {
                $add->execute([
                    ':email' => Chat::BOT_EMAIL, ':body' => $welcome['body'],
                    ':time' => gmdate('Y-m-d H:i:s', $dayStart + ($i + 1) * 60),
                ]);
            }
        }
    }

    /**
     * Clear earlier days' lines and any today's plan doesn't have, and find
     * how far today has got. A fast-forwarded day (DEMO_SPEED) loops back to
     * its start, and a day rewritten part way (a deploy changed the stories)
     * can't have its earlier lines slotted in, as the chat runs in id order;
     * either way, the day starts over
     *
     * @param array $schedule Today's, from schedule()
     * @param int $dayStart Unix time
     *
     * @return int The last line of the schedule already posted, or -1 for none
     */
    protected static function clearUnplannedLines(PDO $db, array $schedule, int $dayStart): int
    {
        $start = gmdate('Y-m-d H:i:s', $dayStart);
        $now = gmdate('Y-m-d H:i:s', static::now());

        // Today's lines are known by who said what, not when: a story that
        // waited for a guest goes out later than planned. Nobody says the
        // same thing twice in a day
        $lineNumbers = [];
        foreach ($schedule as $i => [, $email, $body]) {
            $lineNumbers["$email $body"] = $i;
        }

        $stmt = $db->prepare('SELECT id, email, timestamp, body FROM chats
                              WHERE ' . static::isDemoSql('email') . ' AND to_email IS NULL AND ' . static::NOT_REPLIES);
        $stmt->execute();

        $isRewritten = false;
        $isAhead = false;
        $postedUpTo = -1;
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $line) {
            $i = $lineNumbers[$line['email'] . ' ' . $line['body']] ?? null;
            if ($line['timestamp'] < $start || $i === null) {
                static::deleteLine($db, (int) $line['id']);
                $isRewritten = $isRewritten || $line['timestamp'] >= $start;
                continue;
            }
            $postedUpTo = max($postedUpTo, $i);
            $isAhead = $isAhead || $schedule[$i][0] > $now;
        }

        if ($isAhead || $isRewritten) {
            $db->exec('DELETE FROM chats WHERE ' . static::isDemoSql('email') . ' AND to_email IS NULL AND ' . static::NOT_REPLIES);
            return -1;
        }

        return $postedUpTo;
    }

    /**
     * Post the schedule's lines that are due and not posted yet
     *
     * @param array $schedule Today's, from schedule()
     * @param int $postedUpTo The last line already posted, or -1
     * @param int $dayStart Unix time
     *
     * @return array<string, string> When each demo person last spoke, by email, on the demo clock
     */
    protected static function postDueLines(PDO $db, array $schedule, int $postedUpTo, int $dayStart): array
    {
        $now = gmdate('Y-m-d H:i:s', static::now());

        // Skip the same line if two requests catch up at once
        $insert = $db->prepare('INSERT INTO chats (email, body, timestamp)
                                SELECT :email, :body, :time FROM DUAL
                                WHERE NOT EXISTS (SELECT 1 FROM chats WHERE email = :email2 AND body = :body2 AND timestamp >= :start)');

        $lastSpokeAt = [];
        foreach ($schedule as $i => [$time, $email, $body]) {
            if ($time > $now) {
                break;
            }

            $lastSpokeAt[$email] = $time;

            if ($i > $postedUpTo) {
                $insert->execute([
                    ':email' => $email, ':body' => $body, ':time' => static::realTime($time),
                    ':email2' => $email, ':body2' => $body, ':start' => gmdate('Y-m-d H:i:s', $dayStart),
                ]);
            }
        }

        return $lastSpokeAt;
    }

    /**
     * Note when each demo person last spoke, for "Recently here", on the
     * real clock. A time left in the future by an earlier fast-forwarded day
     * is replaced
     *
     * @param array<string, string> $lastSpokeAt By email, on the demo clock
     */
    protected static function markSeen(PDO $db, array $lastSpokeAt): void
    {
        $seen = $db->prepare('UPDATE userinfo SET online_at = :time
                              WHERE email = :email AND (online_at IS NULL OR online_at < :time2 OR online_at > NOW())');
        foreach ($lastSpokeAt as $email => $time) {
            $realTime = static::realTime($time);
            $seen->execute([':email' => $email, ':time' => $realTime, ':time2' => $realTime]);
        }
    }

    /**
     * Delete one line from the chat
     *
     * @return bool Whether it was still there
     */
    protected static function deleteLine(PDO $db, int $id): bool
    {
        $stmt = $db->prepare('DELETE FROM chats WHERE id = :id');
        $stmt->execute([':id' => $id]);

        return $stmt->rowCount() > 0;
    }

    /**
     * The demo people who are in the room right now: those about to speak,
     * speaking, or who spoke a little while ago
     *
     * @return string[] Their emails
     */
    public static function presentNow(): array
    {
        $now = static::now();
        $present = [];

        foreach (static::schedule(static::today()) as [$time, $email]) {
            $at = strtotime($time . ' UTC');

            if ($now >= $at - static::ARRIVE_MINUTES * 60 && $now <= $at + static::LINGER_MINUTES * 60) {
                $present[$email] = true;
            }
        }

        return array_keys($present);
    }

    /**
     * The stories a demo person has been part of in the room today, from
     * each one's start up to its latest line posted so far, so they can talk
     * about them however long ago they came up. Only lines already in the
     * room count: what's still to come in a story hasn't happened yet
     *
     * @param string $email The demo person
     *
     * @return array<array{lines: array<array{string, string, string}>, isOver: bool}> Oldest first; each line is [name, email, body]
     */
    public static function storiesSoFar(string $email): array
    {
        $firstNames = static::firstNames();
        $stories = require dirname(__DIR__, 2) . '/database/demo-chat.php';

        // Which story each line belongs to, as the room has it (with its @mention)
        $storyOf = [];
        foreach ($stories as $name => $scenes) {
            foreach (array_merge(...$scenes) as [$speaker, $body]) {
                $storyOf[$speaker . static::DOMAIN . ' ' . static::withAddressee($body, $firstNames)] = $name;
            }
        }

        $stmt = static::getDB()->prepare('SELECT email, body FROM chats WHERE ' . static::isDemoSql('email') . ' AND to_email IS NULL
                                            AND timestamp >= :start AND ' . static::NOT_REPLIES . ' ORDER BY id');
        $stmt->execute([':start' => gmdate('Y-m-d H:i:s', static::dayStart(static::today()))]);

        $posted = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $line) {
            $story = $storyOf[$line['email'] . ' ' . $line['body']] ?? null;
            if ($story !== null) {
                $key = strstr($line['email'], '@', true);
                $posted[$story][] = [$firstNames[$key] ?? $key, $line['email'], $line['body']];
            }
        }

        // Theirs: the ones they've spoken in so far. The array keeps the order
        // stories started in; the latest few are kept
        $theirs = array_filter($posted, fn ($lines) => in_array($email, array_column($lines, 1), true));
        $theirs = array_slice($theirs, -static::MOST_STORIES, null, true);

        return array_values(array_map(fn ($lines, $story) => [
            'lines' => $lines,
            'isOver' => count($lines) >= count(array_merge(...$stories[$story])),
        ], $theirs, array_keys($theirs)));
    }

    /**
     * The day's messages in order. The same date always gives the same day,
     * so every request agrees on it
     *
     * @param string $date Y-m-d
     *
     * @return array<array{string, string, string}> Each [UTC time as Y-m-d H:i:s, email, body]
     */
    public static function schedule(string $date): array
    {
        // Worked out once per date and set of names: the chat socket asks
        // every few seconds and for every page that connects. The names are
        // part of it, as lines that name someone tag them (withAddressee)
        static $schedules = [];
        $firstNames = static::firstNames();
        $key = $date . ' ' . json_encode($firstNames);
        if (isset($schedules[$key])) {
            return $schedules[$key];
        }

        mt_srand(crc32($date));

        $scenes = static::orderScenes(require dirname(__DIR__, 2) . '/database/demo-chat.php');

        // Minutes into each scene for each line, then spread the scenes
        // evenly-ish over the whole day so every story ends by midnight
        $offsets = [];
        foreach ($scenes as $scene) {
            $at = 0;
            $lines = [];
            foreach ($scene as $i => $line) {
                if ($i > 0) {
                    $at += static::replyMinutes($scene[$i - 1], $line);
                }
                $lines[] = $at;
            }
            $offsets[] = $lines;
        }

        $dayStart = static::dayStart($date);
        $nextDay = (new \DateTime($date, static::timezone()))->modify('+1 day')->format('Y-m-d');
        $dayMinutes = (static::dayStart($nextDay) - $dayStart) / 60;

        $start = mt_rand(5, 20);
        $busy = array_sum(array_map(fn ($lines) => end($lines), $offsets));
        $free = $dayMinutes - 30 - $start - $busy;
        $weights = array_map(fn () => mt_rand(60, 140), array_slice($scenes, 1));
        $scale = $free / max(1, array_sum($weights));

        $minute = $start;
        $messages = [];

        foreach ($scenes as $n => $scene) {
            foreach ($scene as $i => [$speaker, $body]) {
                $seconds = (int) round(($minute + $offsets[$n][$i]) * 60) + mt_rand(0, 30);
                $messages[] = [gmdate('Y-m-d H:i:s', $dayStart + $seconds), $speaker . static::DOMAIN, static::withAddressee($body, $firstNames)];
            }

            $minute += end($offsets[$n]) + ($weights[$n] ?? 0) * $scale;
        }

        mt_srand();

        // A long-running process keeps only today's and tomorrow's
        if (count($schedules) > 2) {
            array_shift($schedules);
        }
        $schedules[$key] = $messages;

        return $messages;
    }

    /**
     * A line that starts by naming another demo person ("rafael how was
     * it?") starts with an @mention of them instead, so it shows as one
     *
     * @param array<string, string>|null $firstNames From firstNames(), when the caller has them already
     */
    protected static function withAddressee(string $body, ?array $firstNames = null): string
    {
        foreach ($firstNames ?? static::firstNames() as $key => $firstName) {
            $named = '/^(?:' . preg_quote($key, '/') . '|' . preg_quote($firstName, '/') . ')(?![\p{L}\p{N}-])/iu';
            if (preg_match($named, $body)) {
                return preg_replace($named, '@' . $firstName, $body, 1);
            }
        }

        return $body;
    }

    /**
     * Each demo person's first name, by the key their lines use (the start
     * of their email, e.g. jaewon => Jae-won)
     *
     * @return array<string, string>
     */
    protected static function firstNames(): array
    {
        $stmt = static::getDB()->query('SELECT email, name FROM users WHERE ' . static::isDemoSql('email'));

        $names = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $person) {
            $names[strstr($person['email'], '@', true)] = explode(' ', $person['name'])[0];
        }

        return $names;
    }

    /**
     * Whether DEMO_SPEED fast-forwards the day, to watch it locally
     */
    public static function isFast(): bool
    {
        return static::speed() > 1;
    }

    /**
     * DEMO_SPEED: how many demo seconds pass in a real one, at least 1
     */
    protected static function speed(): float
    {
        return max(1, (float) Config::get('DEMO_SPEED', 1));
    }

    /**
     * The time on the demo clock: the real time, held back by however long
     * the story has waited today. With DEMO_SPEED=60 a real second is a demo
     * minute, so a whole day plays in 24 minutes, on repeat
     *
     * @return int Unix time
     */
    protected static function now(): int
    {
        $dayStart = static::dayStart(static::today());

        // The real time the story has had to run: all of it, less its waits
        $ran = time() - Bot::storyPausedSeconds($dayStart);

        if (static::speed() == 1) {
            return $ran;
        }

        return $dayStart + (int) fmod($ran * static::speed(), 86400);
    }

    /**
     * When a moment on the demo clock happens on the real clock: later by
     * however long the story has waited, and with DEMO_SPEED, a demo minute
     * ago was a real second ago (the demo clock can run hours ahead)
     *
     * @param string $time UTC, Y-m-d H:i:s, on the demo clock
     *
     * @return string UTC, Y-m-d H:i:s
     */
    protected static function realTime(string $time): string
    {
        $demoSecondsAgo = static::now() - strtotime($time . ' UTC');
        $realSecondsAgo = (int) round($demoSecondsAgo / static::speed());

        return gmdate('Y-m-d H:i:s', time() - $realSecondsAgo);
    }

    /**
     * Today's date where the demo day runs (DEMO_TIMEZONE, Toronto by default),
     * so its messages are "Today" for people there
     *
     * @return string Y-m-d
     */
    public static function today(): string
    {
        return (new \DateTime('now', static::timezone()))->format('Y-m-d');
    }

    /**
     * Midnight at the start of a demo day
     *
     * @param string $date Y-m-d
     *
     * @return int Unix time
     */
    protected static function dayStart(string $date): int
    {
        return (new \DateTime($date . ' 00:00:00', static::timezone()))->getTimestamp();
    }

    /**
     * DEMO_TIMEZONE, or Toronto when it's unset or not a real time zone, so a
     * typo doesn't take the chat down
     */
    protected static function timezone(): \DateTimeZone
    {
        try {
            return new \DateTimeZone(Config::get('DEMO_TIMEZONE', static::TIMEZONE));
        } catch (\Exception $e) {
            return new \DateTimeZone(static::TIMEZONE);
        }
    }

    /**
     * How long after one line the next one comes: time to read it (unless
     * it's the same person adding a line), a moment to think, then typing
     *
     * @param array{string, string} $previous [speaker, body]
     * @param array{string, string} $line [speaker, body]
     *
     * @return float Minutes
     */
    protected static function replyMinutes(array $previous, array $line): float
    {
        $typing = mb_strlen($line[1]) / static::TYPE_SPEED;

        if ($previous[0] === $line[0]) {
            return $typing + 0.2;
        }

        return mb_strlen($previous[1]) / static::READ_SPEED + mt_rand(50, 200) / 100 + $typing;
    }

    /**
     * One list of scenes from all the stories: the OPENING ones first, then
     * each story's scenes in order, never two from the same story in a row,
     * and longer stories picked more often so they stretch across the day
     *
     * @param array<string, array> $stories As database/demo-chat.php has them
     *
     * @return array[] Scenes, each a list of [speaker, body]
     */
    protected static function orderScenes(array $stories): array
    {
        $scenes = [];
        $lastStory = null;

        foreach (static::OPENING as $name) {
            if (!empty($stories[$name])) {
                $scenes[] = array_shift($stories[$name]);
                $lastStory = $name;
                if (!$stories[$name]) {
                    unset($stories[$name]);
                }
            }
        }

        while ($stories) {
            $choices = array_diff(array_keys($stories), count($stories) > 1 ? [$lastStory] : []);
            $tickets = [];
            foreach ($choices as $name) {
                array_push($tickets, ...array_fill(0, count($stories[$name]), $name));
            }

            $lastStory = $tickets[mt_rand(0, count($tickets) - 1)];
            $scenes[] = array_shift($stories[$lastStory]);

            if (!$stories[$lastStory]) {
                unset($stories[$lastStory]);
            }
        }

        return $scenes;
    }
}

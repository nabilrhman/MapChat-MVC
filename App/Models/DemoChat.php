<?php

namespace App\Models;

use PDO;
use App\Config;

/**
 * The demo people talk in the room through the day: a few running stories
 * and one-off chats (database/demo-chat.php) at a slow pace, in a fresh
 * order each day. There's no timer on the server (the free plan sleeps), so
 * whenever someone has the chat open, catchUp() adds the messages that are
 * due by now, with their scheduled times. Earlier days' are cleared, and the
 * day follows DEMO_TIMEZONE.
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

    /**
     * Add any demo messages that are due, and clear earlier days'. Every open
     * chat page asks for this each second, so only one request does it at a
     * time; the rest skip it. Its changes are saved together or not at all,
     * so a failure part way can't leave a gap: the chat runs in id order, and
     * later lines are only ever added after the last one there
     *
     * @return void
     */
    public static function catchUp()
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
     *
     * @param PDO $db
     *
     * @return void
     */
    protected static function catchUpLocked($db)
    {
        $today = static::today();
        $day_start = static::dayStart($today);
        $start = gmdate('Y-m-d H:i:s', $day_start);

        // The welcome messages open each day, so they're never under "Yesterday".
        // The chat is in id order, so they're added again after the older messages.
        // What MapChat said to people (mapchat_jobs) stays where it was said
        $stmt = $db->prepare('SELECT id, body FROM chats WHERE email = :bot AND to_email IS NULL AND timestamp < :start
                                AND ' . static::NOT_REPLIES . ' ORDER BY id');
        $stmt->execute([':bot' => Chat::BOT_EMAIL, ':start' => $start]);
        $remove = $db->prepare('DELETE FROM chats WHERE id = :id');
        $add = $db->prepare('INSERT INTO chats (email, body, timestamp) VALUES (:email, :body, :time)');
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $i => $welcome) {
            $remove->execute([':id' => $welcome['id']]);

            // Unless another request at the same moment already moved it
            if ($remove->rowCount()) {
                $add->execute([
                    ':email' => Chat::BOT_EMAIL, ':body' => $welcome['body'],
                    ':time' => gmdate('Y-m-d H:i:s', $day_start + ($i + 1) * 60),
                ]);
            }
        }

        $now = gmdate('Y-m-d H:i:s', static::now());

        // Skip the same message if two requests catch up at once
        $insert = $db->prepare('INSERT INTO chats (email, body, timestamp)
                                SELECT :email, :body, :time FROM DUAL
                                WHERE NOT EXISTS (SELECT 1 FROM chats WHERE email = :email2 AND timestamp = :time2)');

        $schedule = static::schedule($today);

        // Clear every demo line today's schedule doesn't have: earlier days',
        // and any left from before the conversations were rewritten. Who and
        // when aren't enough: a rewritten day can put the same person at the
        // same second with different words
        $planned = array_flip(array_map(fn ($line) => $line[1] . ' ' . $line[0] . ' ' . $line[2], $schedule));
        $stmt = $db->prepare('SELECT id, email, timestamp, body FROM chats WHERE email LIKE :demo AND to_email IS NULL AND ' . static::NOT_REPLIES);
        $stmt->execute([':demo' => '%' . static::DOMAIN]);
        $drop = $db->prepare('DELETE FROM chats WHERE id = :id');
        $rewritten = false;
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (!isset($planned[$row['email'] . ' ' . $row['timestamp'] . ' ' . $row['body']])) {
                $drop->execute([':id' => $row['id']]);
                $rewritten = $rewritten || $row['timestamp'] >= $start;
            }
        }

        $stmt = $db->prepare('SELECT MAX(timestamp) FROM chats WHERE email LIKE :demo AND ' . static::NOT_REPLIES);
        $stmt->execute([':demo' => '%' . static::DOMAIN]);
        $last = $stmt->fetchColumn() ?: '';

        // A fast-forwarded day (DEMO_SPEED) loops, and a day rewritten part way
        // (a deploy changed the stories) can't have its earlier lines slotted
        // in, as the chat runs in id order; either way, start the day over
        if ($last > $now || $rewritten) {
            $db->prepare('DELETE FROM chats WHERE email LIKE :demo AND to_email IS NULL AND ' . static::NOT_REPLIES)
               ->execute([':demo' => '%' . static::DOMAIN]);
            $last = '';
        }

        $last_spoke = [];

        foreach ($schedule as [$time, $email, $body]) {
            if ($time > $now) {
                break;
            }

            $last_spoke[$email] = $time;

            if ($time > $last) {
                $insert->execute([
                    ':email' => $email, ':body' => $body, ':time' => $time,
                    ':email2' => $email, ':time2' => $time,
                ]);
            }
        }

        // When they last spoke, for "Recently here", on the real clock. A
        // time left in the future by an earlier fast-forwarded day is replaced
        $seen = $db->prepare('UPDATE userinfo SET online_at = :time
                              WHERE email = :email AND (online_at IS NULL OR online_at < :time2 OR online_at > NOW())');
        foreach ($last_spoke as $email => $time) {
            $real = static::realTime($time);
            $seen->execute([':email' => $email, ':time' => $real, ':time2' => $real]);
        }
    }

    /**
     * The demo people who are in the room right now: those about to speak,
     * speaking, or who spoke a little while ago
     *
     * @return array Their emails
     */
    public static function presentNow()
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
     * The day's messages in order, as [UTC time, email, body]. The same date
     * always gives the same day, so every request agrees on it
     *
     * @param string $date Y-m-d
     *
     * @return array
     */
    public static function schedule($date)
    {
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

        $day_start = static::dayStart($date);
        $next_day = (new \DateTime($date, static::timezone()))->modify('+1 day')->format('Y-m-d');
        $day_minutes = (static::dayStart($next_day) - $day_start) / 60;

        $start = mt_rand(5, 20);
        $busy = array_sum(array_map(fn ($lines) => end($lines), $offsets));
        $free = $day_minutes - 30 - $start - $busy;
        $weights = array_map(fn () => mt_rand(60, 140), array_slice($scenes, 1));
        $scale = $free / max(1, array_sum($weights));

        $minute = $start;
        $messages = [];

        foreach ($scenes as $n => $scene) {
            foreach ($scene as $i => [$speaker, $body]) {
                $seconds = (int) round(($minute + $offsets[$n][$i]) * 60) + mt_rand(0, 30);
                $messages[] = [gmdate('Y-m-d H:i:s', $day_start + $seconds), $speaker . static::DOMAIN, static::withAddressee($body)];
            }

            $minute += end($offsets[$n]) + ($weights[$n] ?? 0) * $scale;
        }

        mt_srand();

        return $messages;
    }

    /**
     * A line that starts by naming another demo person ("rafael how was
     * it?") starts with an @mention of them instead, so it shows as one
     *
     * @param string $body
     *
     * @return string
     */
    protected static function withAddressee($body)
    {
        foreach (static::firstNames() as $key => $firstName) {
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
     * @return array
     */
    protected static function firstNames()
    {
        $stmt = static::getDB()->prepare('SELECT email, name FROM users WHERE email LIKE :demo');
        $stmt->execute([':demo' => '%' . static::DOMAIN]);

        $names = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $person) {
            $names[strstr($person['email'], '@', true)] = explode(' ', $person['name'])[0];
        }

        return $names;
    }

    /**
     * Whether DEMO_SPEED fast-forwards the day, to watch it locally
     *
     * @return bool
     */
    public static function isFast()
    {
        return Config::get('DEMO_SPEED', 1) > 1;
    }

    /**
     * The time on the demo clock. Normally the real time; with DEMO_SPEED=60
     * a real second is a demo minute, so a whole day plays in 24 minutes, on
     * repeat
     *
     * @return int Unix time
     */
    protected static function now()
    {
        $speed = (float) Config::get('DEMO_SPEED', 1);
        if ($speed <= 1) {
            return time();
        }

        $day_start = static::dayStart(static::today());

        return $day_start + (int) (fmod(time() * $speed, 86400));
    }

    /**
     * When a moment on the demo clock happened on the real clock. They're
     * the same unless DEMO_SPEED fast-forwards the day; then a demo minute
     * ago was a real second ago, and the demo clock can run hours ahead
     *
     * @param string $time UTC, Y-m-d H:i:s, on the demo clock
     *
     * @return string UTC, Y-m-d H:i:s
     */
    protected static function realTime($time)
    {
        if (!static::isFast()) {
            return $time;
        }

        $demoSecondsAgo = static::now() - strtotime($time . ' UTC');
        $realSecondsAgo = (int) round($demoSecondsAgo / (float) Config::get('DEMO_SPEED', 1));

        return gmdate('Y-m-d H:i:s', time() - $realSecondsAgo);
    }

    /**
     * Today's date where the demo day runs (DEMO_TIMEZONE, Toronto by default),
     * so its messages are "Today" for people there
     *
     * @return string Y-m-d
     */
    public static function today()
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
    protected static function dayStart($date)
    {
        return (new \DateTime($date . ' 00:00:00', static::timezone()))->getTimestamp();
    }

    /**
     * DEMO_TIMEZONE, or Toronto when it's unset or not a real time zone, so a
     * typo doesn't take the chat down
     *
     * @return \DateTimeZone
     */
    protected static function timezone()
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
     * @param array $previous [speaker, body]
     * @param array $line [speaker, body]
     *
     * @return float Minutes
     */
    protected static function replyMinutes($previous, $line)
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
     * @param array $stories
     *
     * @return array
     */
    protected static function orderScenes($stories)
    {
        $scenes = [];
        $last = null;

        foreach (static::OPENING as $name) {
            if (!empty($stories[$name])) {
                $scenes[] = array_shift($stories[$name]);
                $last = $name;
                if (!$stories[$name]) {
                    unset($stories[$name]);
                }
            }
        }

        while ($stories) {
            $choices = array_diff(array_keys($stories), count($stories) > 1 ? [$last] : []);
            $tickets = [];
            foreach ($choices as $name) {
                array_push($tickets, ...array_fill(0, count($stories[$name]), $name));
            }

            $last = $tickets[mt_rand(0, count($tickets) - 1)];
            $scenes[] = array_shift($stories[$last]);

            if (!$stories[$last]) {
                unset($stories[$last]);
            }
        }

        return $scenes;
    }
}

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

    /** Minutes between messages within a conversation */
    const REPLY_MINUTES = [2, 6];

    /** A demo person shows as online from this many minutes before they speak... */
    const ARRIVE_MINUTES = 5;

    /** ...until this many minutes after */
    const LINGER_MINUTES = 30;

    /** Hour the room wakes up; nobody talks between midnight and then */
    const WAKE_HOUR = 7;

    /**
     * Add any demo messages that are due, and clear earlier days'
     *
     * @return void
     */
    public static function catchUp()
    {
        $db = static::getDB();
        $today = static::today();
        $start = gmdate('Y-m-d H:i:s', static::dayStart($today));

        // The welcome messages open each day, so they're never under "Yesterday"
        $stmt = $db->prepare('SELECT id FROM chats WHERE email = :bot AND to_email IS NULL AND timestamp < :start ORDER BY id');
        $stmt->execute([':bot' => Chat::BOT_EMAIL, ':start' => $start]);
        $restamp = $db->prepare('UPDATE chats SET timestamp = :time WHERE id = :id');
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $i => $id) {
            $restamp->execute([':time' => gmdate('Y-m-d H:i:s', static::dayStart($today) + ($i + 1) * 60), ':id' => $id]);
        }

        $now = gmdate('Y-m-d H:i:s');

        // Skip the same message if two requests catch up at once
        $insert = $db->prepare('INSERT INTO chats (email, body, timestamp)
                                SELECT :email, :body, :time FROM DUAL
                                WHERE NOT EXISTS (SELECT 1 FROM chats WHERE email = :email2 AND timestamp = :time2)');

        $schedule = static::schedule($today);

        // Clear every demo line today's schedule doesn't have: earlier days',
        // and any left from before the conversations were rewritten
        $planned = array_flip(array_map(fn ($line) => $line[1] . ' ' . $line[0], $schedule));
        $stmt = $db->prepare('SELECT id, email, timestamp FROM chats WHERE email LIKE :demo AND to_email IS NULL');
        $stmt->execute([':demo' => '%' . static::DOMAIN]);
        $drop = $db->prepare('DELETE FROM chats WHERE id = :id');
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (!isset($planned[$row['email'] . ' ' . $row['timestamp']])) {
                $drop->execute([':id' => $row['id']]);
            }
        }

        $stmt = $db->prepare('SELECT MAX(timestamp) FROM chats WHERE email LIKE :demo');
        $stmt->execute([':demo' => '%' . static::DOMAIN]);
        $last = $stmt->fetchColumn() ?: '';

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

        // When they last spoke, for "Recently here"
        $seen = $db->prepare('UPDATE userinfo SET online_at = :time
                              WHERE email = :email AND (online_at IS NULL OR online_at < :time2)');
        foreach ($last_spoke as $email => $time) {
            $seen->execute([':email' => $email, ':time' => $time, ':time2' => $time]);
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
        $now = time();
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
            foreach ($scene as $line) {
                $lines[] = $at;
                $at += mt_rand(...static::REPLY_MINUTES);
            }
            $offsets[] = $lines;
        }

        $day_start = static::dayStart($date);
        $day_minutes = (static::dayStart(date('Y-m-d', strtotime($date . ' +1 day'))) - $day_start) / 60;

        // Quiet at night: the room wakes up around 7am
        $start = static::WAKE_HOUR * 60 + mt_rand(0, 20);
        $busy = array_sum(array_map(fn ($lines) => end($lines), $offsets));
        $free = $day_minutes - 30 - $start - $busy;
        $weights = array_map(fn () => mt_rand(60, 140), array_slice($scenes, 1));
        $scale = $free / max(1, array_sum($weights));

        $minute = $start;
        $messages = [];

        foreach ($scenes as $n => $scene) {
            foreach ($scene as $i => [$speaker, $body]) {
                $seconds = (int) round(($minute + $offsets[$n][$i]) * 60) + mt_rand(0, 30);
                $messages[] = [gmdate('Y-m-d H:i:s', $day_start + $seconds), $speaker . static::DOMAIN, $body];
            }

            $minute += end($offsets[$n]) + ($weights[$n] ?? 0) * $scale;
        }

        mt_srand();

        return $messages;
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
     * @return \DateTimeZone
     */
    protected static function timezone()
    {
        return new \DateTimeZone(Config::get('DEMO_TIMEZONE', 'America/Toronto'));
    }

    /**
     * One list of scenes from all the stories: each story's scenes stay in
     * order, never two from the same story in a row, and longer stories are
     * picked more often so they stretch across the day
     *
     * @param array $stories
     *
     * @return array
     */
    protected static function orderScenes($stories)
    {
        $scenes = [];
        $last = null;

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

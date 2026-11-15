<?php

namespace App\Models;

use PDO;

/**
 * The demo people talk in the room through the day: a few running stories
 * and one-off chats (database/demo-chat.php) at a slow pace, in a fresh
 * order each day. There's no timer on the server (the free plan sleeps), so
 * whenever someone has the chat open, catchUp() adds the messages that are
 * due by now, with their scheduled times. Earlier days' are cleared.
 */
class DemoChat extends \Core\Model
{
    /** The demo people's email addresses end with this */
    const DOMAIN = '@demo.mapchat.local';

    /** Minutes between messages within a conversation */
    const REPLY_MINUTES = [2, 6];

    /**
     * Add any demo messages that are due, and clear earlier days'
     *
     * @return void
     */
    public static function catchUp()
    {
        $db = static::getDB();
        $today = gmdate('Y-m-d');

        $stmt = $db->prepare('DELETE FROM chats WHERE email LIKE :demo AND timestamp < :today AND to_email IS NULL');
        $stmt->bindValue(':demo', '%' . static::DOMAIN, PDO::PARAM_STR);
        $stmt->bindValue(':today', $today . ' 00:00:00', PDO::PARAM_STR);
        $stmt->execute();

        $stmt = $db->prepare('SELECT MAX(timestamp) FROM chats WHERE email LIKE :demo');
        $stmt->bindValue(':demo', '%' . static::DOMAIN, PDO::PARAM_STR);
        $stmt->execute();
        $last = $stmt->fetchColumn() ?: '';

        $now = gmdate('Y-m-d H:i:s');

        // Skip the same message if two requests catch up at once
        $insert = $db->prepare('INSERT INTO chats (email, body, timestamp)
                                SELECT :email, :body, :time FROM DUAL
                                WHERE NOT EXISTS (SELECT 1 FROM chats WHERE email = :email2 AND timestamp = :time2)');

        foreach (static::schedule($today) as [$time, $email, $body]) {
            if ($time <= $last || $time > $now) {
                continue;
            }

            $insert->execute([
                ':email' => $email, ':body' => $body, ':time' => $time,
                ':email2' => $email, ':time2' => $time,
            ]);
        }
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

        $start = mt_rand(5, 25);
        $busy = array_sum(array_map(fn ($lines) => end($lines), $offsets));
        $free = 24 * 60 - 30 - $start - $busy;
        $weights = array_map(fn () => mt_rand(60, 140), array_slice($scenes, 1));
        $scale = $free / max(1, array_sum($weights));

        $day_start = strtotime($date . ' 00:00:00 UTC');
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

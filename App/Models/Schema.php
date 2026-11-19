<?php

namespace App\Models;

use App\Bot\Characters;
use PDO;

/**
 * Brings an older database up to date with init.sql
 */
class Schema extends \Core\Model
{
    /**
     * Columns added since the first release, kept in step with init.sql:
     * each column's ALTER, then any data it starts with, run once when the
     * column is added. Their keys are in ADDED_INDEXES and ADDED_CONSTRAINTS,
     * each checked on its own, so one that fails is tried again next start.
     * One change per statement, since TiDB won't add a foreign key alongside
     * other changes. read_at needs to_email, and counts every private
     * message from before it as read, so nobody's old ones come back as
     * unread. is_live_answer starts on what the bot has posted so far
     */
    const ADDED_COLUMNS = [
        ['userinfo', 'country', ['ALTER TABLE userinfo ADD COLUMN country CHAR(2) AFTER city']],
        ['mapchat_jobs', 'speaker', [
            'ALTER TABLE mapchat_jobs ADD COLUMN speaker VARCHAR(255) NOT NULL DEFAULT \'bot@mapchat.local\' AFTER email',
        ]],
        ['users', 'nickname', [
            'ALTER TABLE users ADD COLUMN nickname VARCHAR(20) NULL DEFAULT NULL AFTER name',
        ]],
        ['userinfo', 'timezone', ['ALTER TABLE userinfo ADD COLUMN timezone VARCHAR(64) AFTER country']],
        ['users', 'guest_token_hash', [
            'ALTER TABLE users ADD COLUMN guest_token_hash VARCHAR(64) NULL DEFAULT NULL AFTER is_guest',
        ]],
        ['chats', 'to_email', [
            'ALTER TABLE chats ADD COLUMN to_email VARCHAR(255) NULL DEFAULT NULL AFTER email',
        ]],
        ['chats', 'read_at', [
            'ALTER TABLE chats ADD COLUMN read_at TIMESTAMP NULL DEFAULT NULL AFTER timestamp',
            'UPDATE chats SET read_at = CURRENT_TIMESTAMP WHERE to_email IS NOT NULL',
        ]],
        ['chats', 'client_id', [
            'ALTER TABLE chats ADD COLUMN client_id VARCHAR(36) NULL DEFAULT NULL AFTER read_at',
        ]],
        ['mapchat_jobs', 'reply', ['ALTER TABLE mapchat_jobs ADD COLUMN reply TEXT NULL DEFAULT NULL AFTER posted_id']],
        ['users', 'google_id', [
            'ALTER TABLE users ADD COLUMN google_id VARCHAR(255) NULL DEFAULT NULL AFTER share_location',
        ]],
        ['users', 'google_photo_declined', [
            'ALTER TABLE users ADD COLUMN google_photo_declined BOOLEAN NOT NULL DEFAULT FALSE AFTER google_id',
        ]],
        ['chats', 'original_body', [
            'ALTER TABLE chats ADD COLUMN original_body TEXT NULL DEFAULT NULL AFTER client_id',
        ]],
        ['mapchat_jobs', 'reply_original', [
            'ALTER TABLE mapchat_jobs ADD COLUMN reply_original TEXT NULL DEFAULT NULL AFTER reply',
        ]],
        ['chats', 'is_live_answer', [
            'ALTER TABLE chats ADD COLUMN is_live_answer BOOLEAN NOT NULL DEFAULT FALSE AFTER original_body',
            'UPDATE chats SET is_live_answer = TRUE
             WHERE id IN (SELECT posted_id FROM mapchat_jobs WHERE posted_id IS NOT NULL)',
        ]],
        ['users', 'failed_logins', [
            'ALTER TABLE users ADD COLUMN failed_logins INT NOT NULL DEFAULT 0 AFTER google_photo_declined',
        ]],
        ['users', 'failed_logins_since', [
            'ALTER TABLE users ADD COLUMN failed_logins_since TIMESTAMP NULL DEFAULT NULL AFTER failed_logins',
        ]],
    ];

    /**
     * Indexes added since the first release, as in init.sql, by table and
     * name. After ADDED_COLUMNS, which adds the columns they're on
     */
    const ADDED_INDEXES = [
        ['users', 'nickname', 'ALTER TABLE users ADD UNIQUE KEY nickname (nickname)'],
        ['users', 'guest_token_hash', 'ALTER TABLE users ADD UNIQUE KEY guest_token_hash (guest_token_hash)'],
        ['chats', 'fk_m_to_email', 'ALTER TABLE chats ADD KEY fk_m_to_email (to_email)'],
        ['chats', 'client_id', 'ALTER TABLE chats ADD UNIQUE KEY client_id (email, client_id)'],
        ['users', 'google_id', 'ALTER TABLE users ADD UNIQUE KEY google_id (google_id)'],
        ['chats', 'pair', 'ALTER TABLE chats ADD KEY pair (to_email, email)'],
    ];

    /**
     * Foreign keys added since the first release, as in init.sql, by table
     * and name. After ADDED_INDEXES, which adds the key each one uses
     */
    const ADDED_CONSTRAINTS = [
        ['chats', 'fk_m_to_email', 'ALTER TABLE chats ADD CONSTRAINT fk_m_to_email FOREIGN KEY (to_email) REFERENCES users (email)
            ON DELETE CASCADE ON UPDATE CASCADE'],
    ];

    /** Tables added since the first release, as in init.sql */
    const ADDED_TABLES = [
        'mapchat_jobs' => 'CREATE TABLE mapchat_jobs (
            `id`         INT          NOT NULL AUTO_INCREMENT,
            `kind`       VARCHAR(10)  NOT NULL,
            `email`      VARCHAR(255) NOT NULL,
            `speaker`    VARCHAR(255) NOT NULL DEFAULT \'bot@mapchat.local\',
            `chat_id`    INT          NULL DEFAULT NULL,
            `dedupe_key` VARCHAR(300) NOT NULL,
            `due_at`     TIMESTAMP    NOT NULL,
            `done_at`    TIMESTAMP    NULL DEFAULT NULL,
            `posted_id`  INT          NULL DEFAULT NULL,
            `reply`      TEXT         NULL DEFAULT NULL,
            `reply_original` TEXT     NULL DEFAULT NULL,
            `created_at` TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `dedupe_key` (`dedupe_key`),
            KEY `due` (`done_at`, `due_at`),
            KEY `posted_id` (`posted_id`),
            KEY `fk_j_email` (`email`),
            CONSTRAINT `fk_j_email` FOREIGN KEY (`email`) REFERENCES users (`email`)
              ON DELETE CASCADE
              ON UPDATE CASCADE
          ) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4',
        'push_subscriptions' => 'CREATE TABLE push_subscriptions (
            `id`            INT          NOT NULL AUTO_INCREMENT,
            `email`         VARCHAR(255) NOT NULL,
            `endpoint`      TEXT         NOT NULL,
            `endpoint_hash` CHAR(64)     NOT NULL,
            `p256dh`        VARCHAR(255) NOT NULL,
            `auth`          VARCHAR(255) NOT NULL,
            `created_at`    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `endpoint_hash` (`endpoint_hash`),
            KEY `fk_p_email` (`email`),
            CONSTRAINT `fk_p_email` FOREIGN KEY (`email`) REFERENCES users (`email`)
              ON DELETE CASCADE
              ON UPDATE CASCADE
          ) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4',
        'groq_uses' => 'CREATE TABLE groq_uses (
            `user_id` INT         NOT NULL,
            `kind`    VARCHAR(10) NOT NULL,
            `used_at` TIMESTAMP   NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY `recent` (`user_id`, `kind`, `used_at`),
            CONSTRAINT `fk_g_user` FOREIGN KEY (`user_id`) REFERENCES users (`id`)
              ON DELETE CASCADE
          ) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4',
    ];

    /**
     * The demo people's country and time zone, as in demo.sql. A database
     * seeded before those columns existed has them empty, and the demo
     * people never sign in to fill them
     */
    const DEMO_PLACES = [
        'aiko@demo.mapchat.local'    => ['JP', 'Asia/Tokyo'],
        'rafael@demo.mapchat.local'  => ['PT', 'Europe/Lisbon'],
        'maya@demo.mapchat.local'    => ['CA', 'America/Toronto'],
        'tanvir@demo.mapchat.local'  => ['BD', 'Asia/Dhaka'],
        'wanjiru@demo.mapchat.local' => ['KE', 'Africa/Nairobi'],
        'lukas@demo.mapchat.local'   => ['DE', 'Europe/Berlin'],
        'sofia@demo.mapchat.local'   => ['MX', 'America/Mexico_City'],
        'jaewon@demo.mapchat.local'  => ['KR', 'Asia/Seoul'],
        'emily@demo.mapchat.local'   => ['US', 'America/Boise'],
    ];

    /**
     * Add any of ADDED_TABLES, ADDED_COLUMNS, ADDED_INDEXES and
     * ADDED_CONSTRAINTS the database is missing. Safe to run every start
     *
     * @return string[] What was added, as table, table.column, table index name or table constraint name
     */
    public static function upgrade(): array
    {
        $db = static::getDB();
        $added = [];

        foreach (static::ADDED_TABLES as $table => $sql) {
            if (!static::hasTable($db, $table)) {
                $db->exec($sql);
                $added[] = $table;
            }
        }

        foreach (static::ADDED_COLUMNS as [$table, $column, $statements]) {
            if (static::hasColumn($db, $table, $column)) {
                continue;
            }
            $added[] = "$table.$column";
            foreach ($statements as $sql) {
                $db->exec($sql);
            }
        }

        foreach (static::ADDED_INDEXES as [$table, $index, $sql]) {
            if (!static::hasIndex($db, $table, $index)) {
                $db->exec($sql);
                $added[] = "$table index $index";
            }
        }

        foreach (static::ADDED_CONSTRAINTS as [$table, $constraint, $sql]) {
            if (!static::hasConstraint($db, $table, $constraint)) {
                $db->exec($sql);
                $added[] = "$table constraint $constraint";
            }
        }

        static::fillDemoPlaces($db);
        static::dropDemoPictures($db);
        static::moveSitePictures($db);
        static::placeBot($db);

        return $added;
    }

    /**
     * Give the demo people their country and time zone where they're missing
     */
    protected static function fillDemoPlaces(PDO $db): void
    {
        $stmt = $db->prepare('UPDATE userinfo
                              SET country = COALESCE(country, :country), timezone = COALESCE(timezone, :timezone)
                              WHERE email = :email');

        foreach (static::DEMO_PLACES as $email => [$country, $timezone]) {
            $stmt->execute([':email' => $email, ':country' => $country, ':timezone' => $timezone]);
        }
    }

    /**
     * The demo people used to have picture files; now they get drawn faces
     * (AvatarPicture::face), which only show when no picture is stored
     */
    protected static function dropDemoPictures(PDO $db): void
    {
        $db->exec("UPDATE users SET avatar = NULL
                   WHERE " . Characters::isDemoSql('email') . " AND avatar LIKE '/res/avatars/%'");
    }

    /**
     * The site's own pictures moved from /res/ to /images/, so a stored
     * avatar there (the welcome bot's) follows them. After dropDemoPictures,
     * which still looks for the demo people's old /res/avatars/ files
     */
    protected static function moveSitePictures(PDO $db): void
    {
        $db->exec("UPDATE users SET avatar = CONCAT('/images/', SUBSTRING(avatar, 6)) WHERE avatar LIKE '/res/%'");
    }

    /**
     * Give the welcome bot its place on the map, as in init.sql
     */
    protected static function placeBot(PDO $db): void
    {
        $db->exec("INSERT INTO userinfo (email, ip, lat, lng, city, country)
                   SELECT email, '0.0.0.0', 0, 0, 'Null Island', 'XN' FROM users WHERE email = 'bot@mapchat.local'
                   ON DUPLICATE KEY UPDATE city = COALESCE(city, 'Null Island'), country = COALESCE(country, 'XN'),
                                           lat = COALESCE(lat, 0), lng = COALESCE(lng, 0)");
    }

    /**
     * Whether a table has an index of this name
     */
    protected static function hasIndex(PDO $db, string $table, string $index): bool
    {
        $stmt = $db->prepare('SELECT COUNT(*) FROM information_schema.STATISTICS
                              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table AND INDEX_NAME = :index');
        $stmt->bindValue(':table', $table, PDO::PARAM_STR);
        $stmt->bindValue(':index', $index, PDO::PARAM_STR);
        $stmt->execute();

        return $stmt->fetchColumn() > 0;
    }

    /**
     * Whether a table has a foreign key of this name
     */
    protected static function hasConstraint(PDO $db, string $table, string $constraint): bool
    {
        $stmt = $db->prepare('SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
                              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table AND CONSTRAINT_NAME = :constraint
                                AND CONSTRAINT_TYPE = \'FOREIGN KEY\'');
        $stmt->bindValue(':table', $table, PDO::PARAM_STR);
        $stmt->bindValue(':constraint', $constraint, PDO::PARAM_STR);
        $stmt->execute();

        return $stmt->fetchColumn() > 0;
    }

    /**
     * Whether the database has a table of this name
     */
    protected static function hasTable(PDO $db, string $table): bool
    {
        $stmt = $db->prepare('SELECT COUNT(*) FROM information_schema.TABLES
                              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table');
        $stmt->bindValue(':table', $table, PDO::PARAM_STR);
        $stmt->execute();

        return $stmt->fetchColumn() > 0;
    }

    /**
     * Whether a table has a column of this name
     */
    protected static function hasColumn(PDO $db, string $table, string $column): bool
    {
        $stmt = $db->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS
                              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table AND COLUMN_NAME = :column');
        $stmt->bindValue(':table', $table, PDO::PARAM_STR);
        $stmt->bindValue(':column', $column, PDO::PARAM_STR);
        $stmt->execute();

        return $stmt->fetchColumn() > 0;
    }
}

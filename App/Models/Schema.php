<?php

namespace App\Models;

use PDO;

/**
 * Brings an older database up to date with init.sql
 */
class Schema extends \Core\Model
{
    /**
     * Columns added since the first release, with the statements that add
     * each, kept in step with init.sql. One change per statement, since TiDB
     * won't add a foreign key alongside other changes. to_email goes last: if
     * its foreign key fails, the columns the pages need are already there
     */
    const ADDED_COLUMNS = [
        ['userinfo', 'country', ['ALTER TABLE userinfo ADD COLUMN country CHAR(2) AFTER city']],
        ['userinfo', 'timezone', ['ALTER TABLE userinfo ADD COLUMN timezone VARCHAR(64) AFTER country']],
        ['users', 'guest_token_hash', [
            'ALTER TABLE users ADD COLUMN guest_token_hash VARCHAR(64) NULL DEFAULT NULL AFTER is_guest',
            'ALTER TABLE users ADD UNIQUE KEY guest_token_hash (guest_token_hash)',
        ]],
        ['chats', 'to_email', [
            'ALTER TABLE chats ADD COLUMN to_email VARCHAR(255) NULL DEFAULT NULL AFTER email',
            'ALTER TABLE chats ADD KEY fk_m_to_email (to_email)',
            'ALTER TABLE chats ADD CONSTRAINT fk_m_to_email FOREIGN KEY (to_email) REFERENCES users (email)
               ON DELETE CASCADE ON UPDATE CASCADE',
        ]],
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
     * Add any of ADDED_COLUMNS the database is missing. Safe to run every start
     *
     * @return array The columns added, as table.column
     */
    public static function upgrade()
    {
        $db = static::getDB();
        $added = [];

        foreach (static::ADDED_COLUMNS as [$table, $column, $statements]) {
            if (static::hasColumn($db, $table, $column)) {
                continue;
            }
            $added[] = "$table.$column";
            foreach ($statements as $sql) {
                $db->exec($sql);
            }
        }

        static::fillDemoPlaces($db);
        static::dropDemoPictures($db);
        static::placeBot($db);

        return $added;
    }

    /**
     * Give the demo people their country and time zone where they're missing
     *
     * @param PDO $db
     *
     * @return void
     */
    protected static function fillDemoPlaces($db)
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
     * (Avatar::face), which only show when no picture is stored
     *
     * @param PDO $db
     *
     * @return void
     */
    protected static function dropDemoPictures($db)
    {
        $db->exec("UPDATE users SET avatar = NULL
                   WHERE email LIKE '%@demo.mapchat.local' AND avatar LIKE '/res/avatars/%'");
    }

    /**
     * Give the welcome bot its place on the map, as in init.sql
     *
     * @param PDO $db
     *
     * @return void
     */
    protected static function placeBot($db)
    {
        $db->exec("INSERT INTO userinfo (email, ip, lat, lng, city, country)
                   SELECT email, '0.0.0.0', 0, 0, 'Null Island', 'XN' FROM users WHERE email = 'bot@mapchat.local'
                   ON DUPLICATE KEY UPDATE city = COALESCE(city, 'Null Island'), country = COALESCE(country, 'XN'),
                                           lat = COALESCE(lat, 0), lng = COALESCE(lng, 0)");
    }

    /**
     * @param PDO $db
     * @param string $table
     * @param string $column
     *
     * @return boolean
     */
    protected static function hasColumn($db, $table, $column)
    {
        $stmt = $db->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS
                              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table AND COLUMN_NAME = :column');
        $stmt->bindValue(':table', $table, PDO::PARAM_STR);
        $stmt->bindValue(':column', $column, PDO::PARAM_STR);
        $stmt->execute();

        return $stmt->fetchColumn() > 0;
    }
}

<?php

namespace App\Models;

use App\AvatarPicture;
use App\Bot\Characters;
use App\Config;
use App\Messages\Mention;
use PDO;

/**
 * Where each person is chatting from, and when they were last seen. Kept in
 * the userinfo table, an older name than the class's
 */
class Whereabouts extends \Core\Model
{
    /** Someone counts as online if the chat heard from them this many seconds ago */
    const ONLINE_SECONDS = 60;

    /** markOnline writes at most this often per person, in seconds */
    const MARK_ONLINE_SECONDS = 30;

    /**
     * Decimal places a pin's latitude and longitude keep: about a kilometre,
     * so a pin shows the city, never an exact location
     */
    const PIN_DECIMALS = 2;

    /**
     * What others see of where someone is, as columns of people rows, only
     * while they share their location. The longitude is for their daylight
     * and local time
     */
    const SHARED_PLACE_COLUMNS = [
        'city' => 'userinfo.city',
        'country' => 'userinfo.country',
        'lng' => 'ROUND(userinfo.lng, ' . self::PIN_DECIMALS . ')',
        'timezone' => 'userinfo.timezone',
    ];

    /** The columns of a people row that are numbers, cast to int as rows come in */
    const PERSON_INT_COLUMNS = ['id', 'minutes_ago'];

    /** The columns of a people row that are yes or no, cast to bool as rows come in */
    const PERSON_BOOL_COLUMNS = ['is_demo', 'is_guest', 'is_bot', 'is_online'];

    /**
     * Save a user's IP and look up where it is
     */
    public static function locate(string $email, string $ip): void
    {
        static::saveIP($email, $ip);
        static::saveLocation($email, static::lookupIP($ip));
    }

    /**
     * Save the IP a user was last seen at
     */
    protected static function saveIP(string $email, string $ip): void
    {
        $stmt = static::getDB()->prepare('INSERT INTO userinfo (email, ip) VALUES (:email, :ip)
                                          ON DUPLICATE KEY UPDATE ip = VALUES(ip)');
        $stmt->bindValue(':email', $email, PDO::PARAM_STR);
        $stmt->bindValue(':ip', $ip, PDO::PARAM_STR);
        $stmt->execute();
    }

    /**
     * Save where a user is, with the pin rounded to the city (PIN_DECIMALS)
     *
     * @param array{lat: ?float, lng: ?float, city: ?string, country: ?string} $location As lookupIP gives it
     */
    public static function saveLocation(string $email, array $location): void
    {
        $sql = 'UPDATE userinfo
                SET lat = :lat, lng = :lng, city = :city, country = :country
                WHERE email = :email';

        $stmt = static::getDB()->prepare($sql);
        $stmt->bindValue(':email', $email, PDO::PARAM_STR);
        $stmt->bindValue(':lat', static::roundToCity($location['lat']), PDO::PARAM_STR);
        $stmt->bindValue(':lng', static::roundToCity($location['lng']), PDO::PARAM_STR);
        $stmt->bindValue(':city', $location['city'], PDO::PARAM_STR);
        $stmt->bindValue(':country', $location['country'], PDO::PARAM_STR);
        $stmt->execute();
    }

    /**
     * A latitude or longitude to PIN_DECIMALS places
     */
    protected static function roundToCity(?float $degrees): ?float
    {
        return $degrees === null ? null : round($degrees, static::PIN_DECIMALS);
    }

    /**
     * Whether a user's location should be looked up again: their IP changed
     * since the last lookup, or they have no location or no country (the
     * lookup failed, or they were located before countries were saved)
     */
    public static function needsLocating(string $email, string $ip): bool
    {
        $stmt = static::getDB()->prepare('SELECT ip, lat IS NULL OR country IS NULL AS is_missing FROM userinfo WHERE email = :email');
        $stmt->bindValue(':email', $email, PDO::PARAM_STR);
        $stmt->execute();
        $saved = $stmt->fetch(PDO::FETCH_ASSOC);

        return !$saved || $saved['ip'] !== $ip || (bool) $saved['is_missing'];
    }

    /**
     * The visitor's IP. Through the host's proxy, it's Cloudflare's header,
     * else the last X-Forwarded-For entry (the first is whatever the visitor
     * sent); from anywhere else, the connection's own address
     */
    public static function getRealIP(): string
    {
        $connectedIp = $_SERVER['REMOTE_ADDR'] ?? '';
        // Anyone can send these headers; only the proxy's are worth reading
        if (!static::isProxyAddress($connectedIp)) {
            return $connectedIp;
        }

        if (!empty($_SERVER['HTTP_CF_CONNECTING_IP'])) {
            return trim($_SERVER['HTTP_CF_CONNECTING_IP']);
        }

        if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $forwarded = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
            return trim(end($forwarded));
        }

        return $connectedIp;
    }

    /**
     * Whether a connection came from a private or reserved address: Render's
     * proxy in front of the container, or Docker's locally. A visitor on the
     * internet can't connect from one
     */
    protected static function isProxyAddress(string $ip): bool
    {
        $isIp = filter_var($ip, FILTER_VALIDATE_IP) !== false;
        $isPublic = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;

        return $isIp && !$isPublic;
    }

    /**
     * Look up the approximate location of an IP address
     *
     * Free services limit or block requests from hosting providers, so
     * several are tried in turn until one answers (locationServiceUrls has
     * the order). Private and local addresses (e.g. in Docker) use DEV_FAKE_IP when it is set, so the
     * map still has a point locally
     *
     * @return array{lat: ?float, lng: ?float, city: ?string, country: ?string}
     */
    public static function lookupIP(string $ip): array
    {
        $empty = ['lat' => null, 'lng' => null, 'city' => null, 'country' => null];

        $isPublic = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
        if (!$isPublic) {
            $ip = Config::get('DEV_FAKE_IP');
            if ($ip === null) {
                return $empty;
            }
        }

        foreach (static::locationServiceUrls($ip) as $name => $url) {
            $location = static::fetchLocation($url);
            if ($location !== null) {
                return $location;
            }
            error_log("IP location lookup failed with $name, trying the next service");
        }

        return $empty;
    }

    /**
     * The IP location services to ask about an IP, in the order they're
     * tried: the https ones, then ipstack when IPSTACK_API_KEY is set. It
     * comes last because its free plan is plain http, with the key in the
     * address
     *
     * @return array<string, string> Each service's address, by its name
     */
    public static function locationServiceUrls(string $ip): array
    {
        $ip = urlencode($ip);
        $services = [
            'ipapi.co' => 'https://ipapi.co/' . $ip . '/json/',
            'ipwho.is' => 'https://ipwho.is/' . $ip,
            'geojs' => 'https://get.geojs.io/v1/ip/geo/' . $ip . '.json',
        ];

        $ipstackKey = Config::get('IPSTACK_API_KEY');
        if ($ipstackKey !== null) {
            $services['ipstack'] = 'http://api.ipstack.com/' . $ip . '?access_key=' . urlencode($ipstackKey);
        }

        return $services;
    }

    /**
     * Ask one IP location service. All of them answer with JSON that has
     * latitude, longitude and (usually) city fields
     *
     * @return array{lat: float, lng: float, city: ?string, country: ?string}|null Null if the service didn't give one
     */
    protected static function fetchLocation(string $url): ?array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 4,
            CURLOPT_USERAGENT => 'MapChat',
        ]);
        $json = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        $result = json_decode((string) $json, true);
        if ($status !== 200 || !is_array($result) || !isset($result['latitude'], $result['longitude'])) {
            return null;
        }

        // Some addresses resolve to a country only; its name then stands in for
        // the city. Services call it country_name, or country when that isn't the code
        $countryName = $result['country_name'] ?? (strlen($result['country'] ?? '') > 2 ? $result['country'] : null);

        return [
            'lat' => (float) $result['latitude'],
            'lng' => (float) $result['longitude'],
            'city' => ($result['city'] ?? '') !== '' ? $result['city'] : $countryName,
            'country' => isset($result['country_code']) ? strtoupper($result['country_code']) : null,
        ];
    }

    /**
     * The pins for the map: everyone online now (as in getRecentPeople),
     * except people who hide from it and the MapChat bot, whose Null Island
     * isn't a real place to chat from. Rounded to the city, as rows saved
     * before pins were rounded have more
     *
     * @return array[] Each with lat, lng, city, country, timezone, user_id, name, nickname, is_guest, avatar, is_demo, avatar_src and shown_name
     */
    public static function getAllLocations(): array
    {
        [$demoOnline, $demoParams] = static::demoOnlineSql();

        $sql = 'SELECT ROUND(userinfo.lat, ' . static::PIN_DECIMALS . ') AS lat,
                       ROUND(userinfo.lng, ' . static::PIN_DECIMALS . ') AS lng,
                       userinfo.city, userinfo.country, userinfo.timezone,
                       users.id AS user_id, users.name, users.nickname, users.is_guest, users.avatar,
                       ' . Characters::isDemoSql() . ' AS is_demo
                FROM userinfo
                LEFT JOIN users
                ON userinfo.email = users.email
                WHERE userinfo.lat IS NOT NULL AND userinfo.lng IS NOT NULL
                  AND users.share_location = 1
                  AND users.email <> :bot_email
                  AND (userinfo.online_at > NOW() - INTERVAL :online SECOND OR ' . $demoOnline . ')';

        $stmt = static::getDB()->prepare($sql);
        $stmt->execute([':bot_email' => Chat::BOT_EMAIL, ':online' => static::ONLINE_SECONDS] + $demoParams);
        $pins = static::typedRows($stmt->fetchAll(PDO::FETCH_ASSOC), ['user_id'], ['is_demo', 'is_guest']);

        return Mention::withShownNames(static::withAvatarSrc($pins, 'user_id'));
    }

    /**
     * Mark the user as online now. A chat on the HTTP fallback asks for
     * messages every few seconds, so this only writes when the last mark is
     * older than MARK_ONLINE_SECONDS
     */
    public static function markOnline(string $email): void
    {
        $sql = 'UPDATE userinfo
                SET online_at = NOW()
                WHERE email = :email
                  AND (online_at IS NULL OR online_at < NOW() - INTERVAL :seconds SECOND)';

        $stmt = static::getDB()->prepare($sql);
        $stmt->bindValue(':email', $email, PDO::PARAM_STR);
        $stmt->bindValue(':seconds', static::MARK_ONLINE_SECONDS, PDO::PARAM_INT);
        $stmt->execute();
    }

    /**
     * Where others see the user: their city and country code, if known
     *
     * @return array{city: ?string, country: ?string}|null
     */
    public static function getPlace(string $email): ?array
    {
        $stmt = static::getDB()->prepare('SELECT city, country FROM userinfo WHERE email = :email');
        $stmt->bindValue(':email', $email, PDO::PARAM_STR);
        $stmt->execute();

        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /**
     * Save the time zone the user's browser reports, e.g. "Asia/Dhaka", if
     * it's a real one
     */
    public static function saveTimezone(string $email, mixed $timezone): void
    {
        if (!is_string($timezone) || !in_array($timezone, \DateTimeZone::listIdentifiers(), true)) {
            return;
        }

        $stmt = static::getDB()->prepare('UPDATE userinfo SET timezone = :timezone WHERE email = :email');
        $stmt->bindValue(':timezone', $timezone, PDO::PARAM_STR);
        $stmt->bindValue(':email', $email, PDO::PARAM_STR);
        $stmt->execute();
    }

    /**
     * The shared place columns (SHARED_PLACE_COLUMNS) for a query's SELECT,
     * each empty while its person hides their location
     */
    public static function sharedPlaceSql(): string
    {
        $columns = [];
        foreach (static::SHARED_PLACE_COLUMNS as $name => $value) {
            $columns[] = "IF(users.share_location, $value, NULL) AS $name";
        }

        return implode(', ', $columns);
    }

    /**
     * People seen in the chat in the last day, most recent first. Guests
     * only while they're online: they're throwaway accounts, and a day of
     * them buries everyone else. MapChat is always online while it can
     * chat back
     *
     * @return array[] People rows, with is_online and minutes_ago
     */
    public static function getRecentPeople(): array
    {
        [$demoOnline, $demoParams] = static::demoOnlineSql();
        $botOnline = static::botOnlineSql();

        $sql = 'SELECT ' . static::personColumnsSql() . ',
                       (userinfo.online_at > NOW() - INTERVAL :online SECOND OR ' . $demoOnline . ' OR ' . $botOnline . ') AS is_online,
                       TIMESTAMPDIFF(MINUTE, userinfo.online_at, NOW()) AS minutes_ago
                FROM userinfo
                JOIN users
                ON userinfo.email = users.email
                WHERE (userinfo.online_at > NOW() - INTERVAL 1 DAY AND users.is_guest = 0)
                   OR (userinfo.online_at > NOW() - INTERVAL :guest_online SECOND AND users.is_guest = 1)
                   OR ' . $demoOnline . '
                   OR ' . $botOnline . '
                ORDER BY userinfo.online_at DESC';

        $stmt = static::getDB()->prepare($sql);
        $stmt->execute([':online' => static::ONLINE_SECONDS, ':guest_online' => static::ONLINE_SECONDS] + $demoParams);

        $people = static::typedRows($stmt->fetchAll(PDO::FETCH_ASSOC), static::PERSON_INT_COLUMNS, static::PERSON_BOOL_COLUMNS);

        return static::withNames(static::stripEmails($people));
    }

    /**
     * These people as getRecentPeople lists them, with their email, for the
     * chat socket to say who came or went. Online is the socket's to say
     *
     * @return array[] People rows, with email and minutes_ago
     * @param string[] $emails
     */
    public static function getPeople(array $emails): array
    {
        if (!$emails) {
            return [];
        }

        [$emailList, $emailParams] = static::inList('email', $emails);
        $sql = 'SELECT ' . static::personColumnsSql() . ',
                       COALESCE(TIMESTAMPDIFF(MINUTE, userinfo.online_at, NOW()), 0) AS minutes_ago
                FROM userinfo
                JOIN users
                ON userinfo.email = users.email
                WHERE users.email IN ' . $emailList;

        $stmt = static::getDB()->prepare($sql);
        $stmt->execute($emailParams);
        $people = static::typedRows($stmt->fetchAll(PDO::FETCH_ASSOC), static::PERSON_INT_COLUMNS, static::PERSON_BOOL_COLUMNS);

        return static::withNames($people);
    }

    /**
     * The columns of a people row: who they are, and where while they share it
     */
    protected static function personColumnsSql(): string
    {
        return 'users.id, users.email, users.name, users.avatar, ' . Characters::isDemoSql() . ' AS is_demo,
                users.nickname, users.is_guest, users.email = ' . static::getDB()->quote(Chat::BOT_EMAIL) . ' AS is_bot, '
                . static::sharedPlaceSql();
    }

    /**
     * People rows without their email, which pages never get
     *
     * @param array[] $people
     *
     * @return array[]
     */
    protected static function stripEmails(array $people): array
    {
        return array_map(function (array $person): array {
            unset($person['email']);
            return $person;
        }, $people);
    }

    /**
     * People rows with avatar_src and shown_name, for the scripts
     *
     * @param array[] $people
     *
     * @return array[]
     */
    protected static function withNames(array $people): array
    {
        return Mention::withShownNames(static::withAvatarSrc($people, 'id'));
    }

    /**
     * Everyone the people list shows as online without the chat socket: open
     * tabs on the HTTP fallback, the demo people in the room, and MapChat
     *
     * @return string[] Their emails
     */
    public static function getOnlineEmails(): array
    {
        [$demoOnline, $demoParams] = static::demoOnlineSql();

        $sql = 'SELECT users.email
                FROM userinfo
                JOIN users
                ON userinfo.email = users.email
                WHERE userinfo.online_at > NOW() - INTERVAL :online SECOND
                   OR ' . $demoOnline . '
                   OR ' . static::botOnlineSql();

        $stmt = static::getDB()->prepare($sql);
        $stmt->execute([':online' => static::ONLINE_SECONDS] + $demoParams);

        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    /**
     * Mark people with a chat socket open as online now, so the map, the
     * people list and MapChat see them as polling tabs used to make them
     *
     * @param string[] $emails
     */
    public static function markOnlineNow(array $emails): void
    {
        if (!$emails) {
            return;
        }

        [$emailList, $emailParams] = static::inList('email', $emails);
        static::getDB()->prepare("UPDATE userinfo SET online_at = NOW() WHERE email IN $emailList")->execute($emailParams);
    }

    /**
     * Mark someone whose last chat socket closed as gone, rather than online
     * for another minute. A tab still polling marks them online again
     */
    public static function markGone(string $email): void
    {
        $sql = 'UPDATE userinfo
                SET online_at = NOW() - INTERVAL :online SECOND
                WHERE email = :email AND online_at > NOW() - INTERVAL :online2 SECOND';

        $stmt = static::getDB()->prepare($sql);
        $stmt->bindValue(':email', $email, PDO::PARAM_STR);
        $stmt->bindValue(':online', static::ONLINE_SECONDS, PDO::PARAM_INT);
        $stmt->bindValue(':online2', static::ONLINE_SECONDS, PDO::PARAM_INT);
        $stmt->execute();
    }

    /**
     * SQL that's true for the demo people in the room now: those in the
     * script (DemoChat::presentNow), and those talking with someone who
     * called or messaged them (Bot::talkingDemoPeople)
     *
     * @return array{string, array<string, string>} The SQL and its placeholders' values
     */
    protected static function demoOnlineSql(): array
    {
        $present = array_unique(array_merge(DemoChat::presentNow(), Bot::talkingDemoPeople()));
        [$emailList, $emailParams] = static::inList('demo_online', $present);

        return ["users.email IN $emailList", $emailParams];
    }

    /**
     * SQL that's true for the MapChat account while it can chat back, so
     * people see there's someone to talk to
     */
    protected static function botOnlineSql(): string
    {
        return Bot::canReply() ? 'users.email = ' . static::getDB()->quote(Chat::BOT_EMAIL) : 'FALSE';
    }

    /**
     * Add each person's avatar image address (see AvatarPicture::src)
     *
     * @param array[] $people Rows with a name, an avatar, is_demo and an id, cast where they were fetched
     * @param string $idColumn Which column holds the user's id
     *
     * @return array[]
     */
    protected static function withAvatarSrc(array $people, string $idColumn): array
    {
        foreach ($people as &$person) {
            $person['avatar_src'] = AvatarPicture::src($person[$idColumn], $person['name'], $person['avatar'], $person['is_demo']);
        }

        return $people;
    }
}

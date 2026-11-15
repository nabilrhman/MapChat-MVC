<?php

namespace App\Models;

use PDO;
use \App\Token;
use \App\Mail;
use \Core\View;
use \App\Config;

/**
 * User model
 *
 * PHP version 7.0
 */
class UserInfo extends \Core\Model
{
    /** Someone counts as online if the chat heard from them this many seconds ago */
    const ONLINE_SECONDS = 60;

    /**
     * So the room never looks empty, about a third of the demo people (from
     * demo.sql) always show as online; which third changes every hour
     */
    const DEMO_ONLINE = "(users.email LIKE '%@demo.mapchat.local' AND MOD(users.id + HOUR(NOW()), 3) = 0)";

    /**
     * Error messages
     *
     * @var array
     */
    public $errors = [];

    /**
     * Class constructor
     *
     * @param array $data Initial property values (optional)
     *
     * @return void
     */
    public function __construct($data = [])
    {
        foreach ($data as $key => $value)
        {
            $this->$key = $value;
        };

    }

    /**
     * Saves the user IP address
     *
     * @return boolean  True if the IP was saved, false otherwise
     */
    public function saveIP()
    {
        $stmt = static::getDB()->prepare('INSERT INTO userinfo (email, ip) VALUES (:email, :ip)
                                          ON DUPLICATE KEY UPDATE ip = VALUES(ip)');
        $stmt->bindValue(':email', $this->email, PDO::PARAM_STR);
        $stmt->bindValue(':ip', $this->ip, PDO::PARAM_STR);

        return $stmt->execute();
    }

    /**
     * Gets the user IP address
     *
     * @return boolean  True if the IP was saved, false otherwise
     */
    public function getIP()
    {

        if (empty($this->errors))
        {

            $sql = 'SELECT userinfo.ip FROM userinfo
                    WHERE userinfo.email = (:email)';

            $db = static::getDB();
            $stmt = $db->prepare($sql);

            $stmt->bindValue(':email', $this->email, PDO::PARAM_STR);
            $stmt->execute();

            $ip = $stmt->fetchColumn();

            return $ip;
        }

        return false;
    }

    /**
     * Saves the user's location
     *
     * @return boolean  True if the IP was saved, false otherwise
     */
    public function saveLocation()
    {

        if (empty($this->errors))
        {
            $sql = 'UPDATE userinfo
                    SET lat = :lat, lng = :lng, city = :city, country = :country
                    WHERE email = :email';

            $db = static::getDB();
            $stmt = $db->prepare($sql);

            $stmt->bindValue(':email', $this->email, PDO::PARAM_STR);
            $stmt->bindValue(':lat', $this->lat, PDO::PARAM_STR);
            $stmt->bindValue(':lng', $this->long, PDO::PARAM_STR);
            $stmt->bindValue(':city', $this->city, PDO::PARAM_STR);
            $stmt->bindValue(':country', $this->country, PDO::PARAM_STR);

            return $stmt->execute();
        }

        return false;
    }

    /**
     * Gets the user IP address
     *
     * @return boolean  True if the IP was saved, false otherwise
     */
    public function getLocation()
    {

        if (empty($this->errors))
        {

            $sql = 'SELECT userinfo.lat, userinfo.lng FROM userinfo
                    WHERE userinfo.email = (:email)';

            $db = static::getDB();
            $stmt = $db->prepare($sql);

            $stmt->bindValue(':email', $this->email, PDO::PARAM_STR);
            $stmt->execute();

            $ip = $stmt->fetchColumn();

            return $ip;
        }

        return false;
    }

    /**
     * Gets the user IP address
     *
     * @return boolean  True if the IP was saved, false otherwise
     */
    public function findLocationFromIP()
    {

        if (empty($this->errors))
        {

            $location = static::lookupIP($this->ip);

            $this->lat = $location['lat'];
            $this->long = $location['lng'];
            $this->city = $location['city'];
            $this->country = $location['country'];

        }

        return false;
    }

    /**
     * Gets the user IP address
     *
     * @return boolean  True if the IP was saved, false otherwise
     */
    public function hasIPUpdated()
    {
        if (empty($this->errors))
        {

            $sql = 'SELECT userinfo.ip FROM userinfo
                    WHERE userinfo.email = :email ';

            $db = static::getDB();
            $stmt = $db->prepare($sql);

            $stmt->bindValue(':email', $this->email, PDO::PARAM_STR);
            $stmt->execute();

            $result = $stmt->fetchColumn();

            if ($result != $this->ip)
            {
                return true;
            }
            else
            {
                return false;
            }
        }

        return false;
    }

    public function isIPAvailable()
    {
        {
            if (empty($this->errors))
            {

                $sql = 'SELECT userinfo.ip FROM userinfo
                        WHERE userinfo.email = :email ';

                $db = static::getDB();
                $stmt = $db->prepare($sql);

                $stmt->bindValue(':email', $this->email, PDO::PARAM_STR);
                $stmt->execute();

                $result = $stmt->fetchColumn();

                if (empty($result))
                {
                    return false;
                }
                else
                {
                    return true;
                }
            }

            return false;
        }
    }

    /**
     * The visitor's IP: Cloudflare's header, which visitors can't fake, else
     * the last X-Forwarded-For entry (the first is whatever the visitor sent)
     *
     * @return string
     */
    public static function getRealIP()
    {
        if (!empty($_SERVER['HTTP_CF_CONNECTING_IP'])) {
            return trim($_SERVER['HTTP_CF_CONNECTING_IP']);
        }

        if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $forwarded = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
            return trim(end($forwarded));
        }

        return $_SERVER['REMOTE_ADDR'] ?? '';
    }

    /**
     * Look up the approximate location of an IP address.
     *
     * Free services limit or block requests from hosting providers, so
     * several are tried in turn until one answers: ipstack (only when
     * IPSTACK_API_KEY is set), then ipapi.co, ipwho.is and GeoJS. Private and
     * local addresses (e.g. in Docker) use DEV_FAKE_IP when it is set, so the
     * map still has a point locally.
     *
     * @param string $ip
     *
     * @return array ['lat' => ?float, 'lng' => ?float, 'city' => ?string]
     */
    public static function lookupIP($ip)
    {
        $empty = ['lat' => null, 'lng' => null, 'city' => null, 'country' => null];

        $isPublic = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
        if (!$isPublic) {
            $ip = Config::get('DEV_FAKE_IP');
            if ($ip === null) {
                return $empty;
            }
        }

        $ip = urlencode($ip);
        $services = [];
        if ($key = Config::get('IPSTACK_API_KEY')) {
            $services['ipstack'] = 'http://api.ipstack.com/' . $ip . '?access_key=' . urlencode($key);
        }
        $services['ipapi.co'] = 'https://ipapi.co/' . $ip . '/json/';
        $services['ipwho.is'] = 'https://ipwho.is/' . $ip;
        $services['geojs'] = 'https://get.geojs.io/v1/ip/geo/' . $ip . '.json';

        foreach ($services as $name => $url) {
            $location = static::fetchLocation($url);
            if ($location !== null) {
                return $location;
            }
            error_log("IP location lookup failed with $name, trying the next service");
        }

        return $empty;
    }

    /**
     * Ask one IP location service. All of them answer with JSON that has
     * latitude, longitude and (usually) city fields.
     *
     * @param string $url
     *
     * @return array|null The location, or null if the service didn't give one
     */
    protected static function fetchLocation($url)
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 4,
            CURLOPT_USERAGENT => 'MapChat',
        ]);
        $json = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $result = json_decode((string) $json, true);
        if ($status !== 200 || !is_array($result) || !isset($result['latitude'], $result['longitude'])) {
            return null;
        }

        return [
            'lat' => (float) $result['latitude'],
            'lng' => (float) $result['longitude'],
            'city' => $result['city'] ?? null,
            'country' => isset($result['country_code']) ? strtoupper($result['country_code']) : null,
        ];
    }

    public function getAllLocations()
    {

        if (empty($this->errors))
        {

            $sql = 'SELECT userinfo.lat, userinfo.lng, userinfo.city, userinfo.country,
                           users.id AS user_id, users.name, users.avatar
                    FROM userinfo
                    LEFT JOIN users
                    ON userinfo.email = users.email
                    WHERE userinfo.lat IS NOT NULL AND userinfo.lng IS NOT NULL
                      AND users.share_location = 1';

            $db = static::getDB();
            $stmt = $db->prepare($sql);

            $stmt->execute();

            $locations = $stmt->fetchAll(PDO::FETCH_ASSOC);

            return static::withAvatarSrc($locations, 'user_id');
        }

        return false;
    }


    /**
     * Mark the user as online now. The chat asks for messages every second, so
     * this only writes when the last mark is over 30 seconds old
     *
     * @param string $email
     *
     * @return void
     */
    public static function markOnline($email)
    {
        $sql = 'UPDATE userinfo
                SET online_at = NOW()
                WHERE email = :email
                  AND (online_at IS NULL OR online_at < NOW() - INTERVAL 30 SECOND)';

        $stmt = static::getDB()->prepare($sql);
        $stmt->bindValue(':email', $email, PDO::PARAM_STR);
        $stmt->execute();
    }

    /**
     * Where others see the user: their city and country code, if known
     *
     * @param string $email
     *
     * @return array|false
     */
    public static function getPlace($email)
    {
        $stmt = static::getDB()->prepare('SELECT city, country FROM userinfo WHERE email = :email');
        $stmt->bindValue(':email', $email, PDO::PARAM_STR);
        $stmt->execute();

        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    /**
     * True if the user has no location or no country: the lookup failed last
     * time, or they were located before countries were saved
     *
     * @return boolean
     */
    public function isMissingLocation()
    {
        $stmt = static::getDB()->prepare(
            'SELECT 1 FROM userinfo WHERE email = :email AND (lat IS NULL OR country IS NULL)'
        );
        $stmt->bindValue(':email', $this->email, PDO::PARAM_STR);
        $stmt->execute();

        return $stmt->fetchColumn() !== false;
    }

    /**
     * Save the time zone the user's browser reports, e.g. "Asia/Dhaka"
     *
     * @param string $email
     * @param string $timezone
     *
     * @return void
     */
    public static function saveTimezone($email, $timezone)
    {
        $sql = 'UPDATE userinfo SET timezone = :timezone WHERE email = :email';

        $stmt = static::getDB()->prepare($sql);
        $stmt->bindValue(':timezone', $timezone, PDO::PARAM_STR);
        $stmt->bindValue(':email', $email, PDO::PARAM_STR);
        $stmt->execute();
    }

    /**
     * People seen in the chat in the last day, most recent first
     *
     * @return array
     */
    public static function getRecentPeople()
    {
        $sql = 'SELECT users.id, users.name, users.avatar,
                       IF(users.share_location, userinfo.city, NULL) AS city,
                       IF(users.share_location, userinfo.country, NULL) AS country,
                       (userinfo.online_at > NOW() - INTERVAL :online SECOND OR ' . static::DEMO_ONLINE . ') AS is_online,
                       TIMESTAMPDIFF(MINUTE, userinfo.online_at, NOW()) AS minutes_ago
                FROM userinfo
                JOIN users
                ON userinfo.email = users.email
                WHERE userinfo.online_at > NOW() - INTERVAL 1 DAY OR ' . static::DEMO_ONLINE . '
                ORDER BY userinfo.online_at DESC';

        $stmt = static::getDB()->prepare($sql);
        $stmt->bindValue(':online', static::ONLINE_SECONDS, PDO::PARAM_INT);
        $stmt->execute();

        return static::withAvatarSrc($stmt->fetchAll(PDO::FETCH_ASSOC), 'id');
    }

    /**
     * Add each person's avatar image address (see Avatar::src)
     *
     * @param array $people Rows with a name, an avatar and an id
     * @param string $id_column Which column holds the user's id
     *
     * @return array
     */
    protected static function withAvatarSrc($people, $id_column)
    {
        foreach ($people as &$person) {
            $person['avatar_src'] = \App\Avatar::src($person[$id_column], $person['name'], $person['avatar']);
        }

        return $people;
    }
}

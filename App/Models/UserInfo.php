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

        if (empty($this->errors))
        {

            if ($this->isIPAvailable())
            {
                $sql = 'UPDATE userinfo
                    SET ip = :ip
                    WHERE email = :email';

                $db = static::getDB();
                $stmt = $db->prepare($sql);

                $stmt->bindValue(':email', $this->email, PDO::PARAM_STR);
                $stmt->bindValue(':ip', $this->ip, PDO::PARAM_STR);

                return $stmt->execute();
            }
            else
            {
                $sql = 'INSERT INTO userinfo (email, ip)
                    VALUES (:email, :ip)';
                $db = static::getDB();
                $stmt = $db->prepare($sql);

                $stmt->bindValue(':email', $this->email, PDO::PARAM_STR);
                $stmt->bindValue(':ip', $this->ip, PDO::PARAM_STR);

                return $stmt->execute();
            }


        }

        return false;
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
                    SET lat = :lat, lng = :lng, city = :city
                    WHERE email = :email';

            $db = static::getDB();
            $stmt = $db->prepare($sql);

            $stmt->bindValue(':email', $this->email, PDO::PARAM_STR);
            $stmt->bindValue(':lat', $this->lat, PDO::PARAM_STR);
            $stmt->bindValue(':lng', $this->long, PDO::PARAM_STR);
            $stmt->bindValue(':city', $this->city, PDO::PARAM_STR);

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
     * The visitor's IP address. Behind a host's proxy the real address is the
     * first entry of X-Forwarded-For.
     *
     * @return string
     */
    public static function getRealIP()
    {
        if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            return trim(explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])[0]);
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
        $empty = ['lat' => null, 'lng' => null, 'city' => null];

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
        ];
    }

    public function getAllLocations()
    {

        if (empty($this->errors))
        {

            $sql = 'SELECT userinfo.lat, userinfo.lng, users.name
                    FROM userinfo
                    LEFT JOIN users
                    ON userinfo.email = users.email
                    WHERE userinfo.lat IS NOT NULL AND userinfo.lng IS NOT NULL
                      AND users.share_location = 1';

            $db = static::getDB();
            $stmt = $db->prepare($sql);

            $stmt->execute();

            $locations = $stmt->fetchAll(PDO::FETCH_ASSOC);

            return $locations;
        }

        return false;
    }


}




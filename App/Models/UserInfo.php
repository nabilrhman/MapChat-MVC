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
     * Uses ipstack when IPSTACK_API_KEY is set, otherwise the keyless
     * ipapi.co service. Private and local addresses (e.g. in Docker) fall
     * back to DEV_FAKE_IP when set, so the map still has a point locally.
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

        $key = Config::get('IPSTACK_API_KEY');
        $url = $key
            ? 'http://api.ipstack.com/' . urlencode($ip) . '?access_key=' . urlencode($key)
            : 'https://ipapi.co/' . urlencode($ip) . '/json/';

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 5,
            CURLOPT_USERAGENT => 'MapChat',
        ]);
        $json = curl_exec($ch);
        curl_close($ch);

        $result = json_decode((string) $json, true);
        if (!is_array($result) || !isset($result['latitude'], $result['longitude'])) {
            return $empty;
        }

        return [
            'lat' => $result['latitude'],
            'lng' => $result['longitude'],
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




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

            // Initialize CURL
            $ch = curl_init('http://api.ipstack.com/' . $this->ip . '?access_key=' . Config::IPSTACK_API_KEY);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

            // Store the data
            $json = curl_exec($ch);
            curl_close($ch);

            //Decode JSON response
            $api_result = json_decode($json, true);

            $this->lat = $api_result['latitude'];
            $this->long = $api_result['longitude'];
            $this->city = $api_result['city'];

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

    public static function getRealIP()
    {
        if (!empty($_SERVER['HTTP_CLIENT_IP']))   //check ip from share internet
        {
            $ip=$_SERVER['HTTP_CLIENT_IP'];
        }
        elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR']))   //to check ip is pass from proxy
        {
            $ip=$_SERVER['HTTP_X_FORWARDED_FOR'];
        }
        else
        {
            $ip=$_SERVER['REMOTE_ADDR'];
        }
        return $ip;
    }

    public function getAllLocations()
    {

        if (empty($this->errors))
        {

            $sql = 'SELECT userinfo.lat, userinfo.lng, users.name
                    FROM userinfo
                    LEFT JOIN users
                    ON userinfo.email = users.email';

            $db = static::getDB();
            $stmt = $db->prepare($sql);

            $stmt->execute();

            $locations = $stmt->fetchAll(PDO::FETCH_ASSOC);

            return $locations;
        }

        return false;
    }


}




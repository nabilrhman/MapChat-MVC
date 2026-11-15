<?php

namespace Core;

use PDO;
use App\Config;

/**
 * Base model
 *
 * PHP version 7.0
 */
#[\AllowDynamicProperties]
abstract class Model
{

    /**
     * Get the PDO database connection
     *
     * @return mixed
     */
    protected static function getDB()
    {
        static $db = null;

        if ($db === null) {
            $dsn = 'mysql:host=' . Config::get('DB_HOST', 'localhost')
                . ';port=' . Config::get('DB_PORT', '3306')
                . ';dbname=' . Config::get('DB_NAME', 'mapchat')
                . ';charset=utf8mb4';

            $options = [];
            // Hosted MySQL services usually require TLS
            if (Config::flag('DB_SSL')) {
                $options[PDO::MYSQL_ATTR_SSL_CA] = Config::get('DB_SSL_CA', '/etc/ssl/certs/ca-certificates.crt');
            }

            $db = new PDO($dsn, Config::get('DB_USER', 'root'), Config::get('DB_PASSWORD', ''), $options);

            // Throw an Exception when an error occurs
            $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        }

        return $db;
    }
}

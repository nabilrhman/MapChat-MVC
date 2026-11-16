<?php

namespace Core;

use PDO;
use App\Config;

/**
 * Base model
 */
#[\AllowDynamicProperties]
abstract class Model
{
    /** The connection every model shares, made on first use */
    private static $db = null;

    /**
     * Class constructor
     *
     * @param array $data  Initial property values (optional)
     *
     * @return void
     */
    public function __construct($data = [])
    {
        foreach ($data as $key => $value) {
            $this->$key = $value;
        }
    }

    /**
     * Get the PDO database connection
     *
     * @return PDO
     */
    protected static function getDB()
    {
        if (self::$db === null) {
            $dsn = 'mysql:host=' . Config::get('DB_HOST', 'localhost')
                . ';port=' . Config::get('DB_PORT', '3306')
                . ';dbname=' . Config::get('DB_NAME', 'mapchat')
                . ';charset=utf8mb4';

            // Times are stored and read as UTC; the browser shows them in local time
            $options = [PDO::MYSQL_ATTR_INIT_COMMAND => "SET time_zone = '+00:00'"];
            // Hosted MySQL services usually require TLS
            if (Config::flag('DB_SSL')) {
                $options[PDO::MYSQL_ATTR_SSL_CA] = Config::get('DB_SSL_CA', '/etc/ssl/certs/ca-certificates.crt');
            }

            self::$db = new PDO($dsn, Config::get('DB_USER', 'root'), Config::get('DB_PASSWORD', ''), $options);

            // Throw an Exception when an error occurs
            self::$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        }

        return self::$db;
    }

    /**
     * Drop the connection, so the next query makes a new one. For the
     * long-running chat processes, whose connection the database can close
     *
     * @return void
     */
    public static function disconnectDB()
    {
        self::$db = null;
    }
}

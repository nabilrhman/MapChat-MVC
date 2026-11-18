<?php

namespace Core;

use App\Config;
use PDO;

/**
 * What every model shares: the database connection and query helpers
 */
abstract class Model
{
    /** The connection every model shares, made on first use */
    private static ?PDO $db = null;

    /**
     * Fill in the model's properties
     *
     * @param array<string, mixed> $data Property values by name
     */
    public function __construct(array $data = [])
    {
        foreach ($data as $key => $value) {
            $this->$key = $value;
        }
    }

    /**
     * The database connection, made on first use
     */
    protected static function getDB(): PDO
    {
        if (self::$db === null) {
            $dsn = 'mysql:host=' . Config::get('DB_HOST', 'localhost')
                . ';port=' . Config::get('DB_PORT', '3306')
                . ';dbname=' . Config::get('DB_NAME', 'mapchat')
                . ';charset=utf8mb4';

            // Times are stored and read as UTC; the browser shows them in local time
            $options = [PDO::MYSQL_ATTR_INIT_COMMAND => "SET time_zone = '+00:00'"];
            // Hosted MySQL services usually require TLS
            if (Config::isOn('DB_SSL')) {
                $options[PDO::MYSQL_ATTR_SSL_CA] = Config::get('DB_SSL_CA', '/etc/ssl/certs/ca-certificates.crt');
            }

            self::$db = new PDO($dsn, Config::get('DB_USER', 'root'), Config::get('DB_PASSWORD', ''), $options);
            self::$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        }

        return self::$db;
    }

    /**
     * An IN (...) list of named placeholders for some values, so the values
     * are bound rather than written into the SQL. No values give (NULL),
     * which matches nothing
     *
     * @param string $name The placeholders' name, numbered: email gives :email0, :email1
     * @param array $values Strings or numbers
     *
     * @return array{string, array<string, mixed>} The list, e.g. "(:email0, :email1)", and the values by placeholder
     */
    protected static function inList(string $name, array $values): array
    {
        $params = [];
        foreach (array_values($values) as $i => $value) {
            $params[":$name$i"] = $value;
        }

        return ['(' . ($params ? implode(', ', array_keys($params)) : 'NULL') . ')', $params];
    }

    /**
     * Rows as the database gives them (every value a string), with these
     * columns made int or bool. A null stays null, as from a LEFT JOIN that
     * found no row
     *
     * @param array[] $rows
     * @param string[] $intColumns
     * @param string[] $boolColumns
     *
     * @return array[]
     */
    protected static function typedRows(array $rows, array $intColumns = [], array $boolColumns = []): array
    {
        foreach ($rows as &$row) {
            foreach ($intColumns as $column) {
                if (isset($row[$column])) {
                    $row[$column] = (int) $row[$column];
                }
            }
            foreach ($boolColumns as $column) {
                if (isset($row[$column])) {
                    $row[$column] = (bool) $row[$column];
                }
            }
        }

        return $rows;
    }

    /**
     * Drop the connection, so the next query makes a new one. For the
     * long-running chat processes, whose connection the database can close
     */
    public static function disconnectDB(): void
    {
        self::$db = null;
    }
}

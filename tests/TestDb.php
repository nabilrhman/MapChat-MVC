<?php

namespace Tests;

use PDO;

/**
 * Gives the tests the app's own database connection
 */
class TestDb extends \Core\Model
{
    /**
     * The app's own database connection
     */
    public static function get(): PDO
    {
        return static::getDB();
    }
}

<?php

/**
 * Builds a fresh test database from database/init.sql before the tests run
 */

require dirname(__DIR__) . '/vendor/autoload.php';
require __DIR__ . '/Support/TestDb.php';
require __DIR__ . '/Support/DrivesTheSite.php';
require __DIR__ . '/Support/SocketClient.php';

use App\Config;

$name = Config::get('DB_NAME', 'mapchat');

// The database is dropped below; never let that be the app's own
if (!str_ends_with($name, '_test')) {
    fwrite(STDERR, "DB_NAME must end in _test (it is \"$name\"); run the tests with `docker compose -p <name> run --rm --build test`\n");
    exit(1);
}

$server = new PDO(
    'mysql:host=' . Config::get('DB_HOST', 'localhost') . ';port=' . Config::get('DB_PORT', '3306') . ';charset=utf8mb4',
    Config::get('DB_USER', 'root'),
    Config::get('DB_PASSWORD', ''),
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

$server->exec("DROP DATABASE IF EXISTS `$name`");
$server->exec("CREATE DATABASE `$name` CHARACTER SET utf8mb4");
$server->exec("USE `$name`");

// Same time zone as the app's connection, so NOW() in the seed rows matches
$server->exec("SET time_zone = '+00:00'");

// One statement at a time, so an error in any of them stops the run
$sql = preg_replace('/^--.*$/m', '', file_get_contents(dirname(__DIR__) . '/database/init.sql'));
foreach (array_filter(array_map('trim', explode(";\n", $sql))) as $statement) {
    $server->exec($statement);
}

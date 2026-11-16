<?php

/**
 * Brings an older database up to date with init.sql (App\Models\Schema::upgrade):
 * adds the tables, columns and indexes it's missing, gives the demo people
 * their country and time zone, drops their old picture files and puts the
 * bot on the map. The container runs this alongside Apache when it starts:
 * php database/migrate.php
 */
require dirname(__DIR__) . '/vendor/autoload.php';

try {
    $added = App\Models\Schema::upgrade();
    echo $added ? 'Added ' . implode(', ', $added) . "\n" : "Database is up to date\n";
} catch (Exception $e) {
    // Start the site anyway; the error shows in the host's logs
    fwrite(STDERR, 'Database upgrade failed: ' . $e->getMessage() . "\n");
}

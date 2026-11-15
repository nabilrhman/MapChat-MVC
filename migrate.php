<?php

/**
 * Adds columns an older database is missing. The container runs this before
 * starting Apache: php migrate.php
 */
require __DIR__ . '/vendor/autoload.php';

try {
    $added = App\Models\Schema::upgrade();
    echo $added ? 'Added ' . implode(', ', $added) . "\n" : "Database is up to date\n";
} catch (Exception $e) {
    // Start the site anyway; the error shows in the host's logs
    fwrite(STDERR, 'Database upgrade failed: ' . $e->getMessage() . "\n");
}

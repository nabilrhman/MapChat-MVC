<?php

/**
 * Router for PHP's built-in server in the HTTP tests. Does what
 * public/.htaccess does under Apache: real files are served as they are,
 * anything else goes to index.php with the path as the query string
 */

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

if ($path !== '/' && is_file($_SERVER['DOCUMENT_ROOT'] . $path)) {
    return false;
}

$query = parse_url($_SERVER['REQUEST_URI'], PHP_URL_QUERY);
$_SERVER['QUERY_STRING'] = ltrim($path, '/') . ($query ? '&' . $query : '');

require $_SERVER['DOCUMENT_ROOT'] . '/index.php';

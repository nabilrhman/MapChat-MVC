<?php

use App\Config;
/**
 * Front controller
 *
 * PHP version 7.0
 */

/**
 * Composer
 */
require dirname(__DIR__) . '/vendor/autoload.php';


/**
 * Error and Exception handling
 */
error_reporting(E_ALL);
set_error_handler('Core\Error::errorHandler');
set_exception_handler('Core\Error::exceptionHandler');


/**
 * Sessions
 */
// Stored in the database, so restarting the container doesn't log anyone out.
// A session lasts a week without activity.
session_set_save_handler(new Core\DatabaseSessionHandler(), true);
session_start([
    'gc_maxlifetime' => 60 * 60 * 24 * 7,
    'cookie_httponly' => true,
    'cookie_samesite' => 'Lax',
    'cookie_secure' => str_starts_with(Config::appUrl(), 'https://'),
]);


/**
 * Routing
 */
$router = new Core\Router();

// Add the routes
$router->add('', ['controller' => 'Home', 'action' => 'index']);
$router->add('about', ['controller' => 'About', 'action' => 'index']);
$router->add('app', ['controller' => 'App', 'action' => 'index']);
$router->add('login', ['controller' => 'Login', 'action' => 'new']);
$router->add('logout', ['controller' => 'Login', 'action' => 'destroy']);
$router->add('password/reset/{token:[\da-f]+}', ['controller' => 'Password', 'action' => 'reset']);
$router->add('signup/activate/{token:[\da-f]+}', ['controller' => 'Signup', 'action' => 'activate']);
$router->add('avatar/{id:\d+}', ['controller' => 'Avatar', 'action' => 'show']);
$router->add('{controller}/{action}');

$router->dispatch($_SERVER['QUERY_STRING']);


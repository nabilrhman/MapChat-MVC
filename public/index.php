<?php

use App\Config;
use App\Controllers\About;
use App\Controllers\Account;
use App\Controllers\App;
use App\Controllers\Avatar;
use App\Controllers\Home;
use App\Controllers\Login;
use App\Controllers\Password;
use App\Controllers\Profile;
use App\Controllers\Signup;
/**
 * Front controller
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
    // Refuse session ids the server didn't create
    'use_strict_mode' => true,
    'cookie_httponly' => true,
    'cookie_samesite' => 'Lax',
    'cookie_secure' => str_starts_with(Config::appUrl(), 'https://'),
]);


/**
 * Routing
 */
$router = new Core\Router();

// Each route names its method and the exact action it runs; no other method
// of a controller can be reached from a URL

// Pages
$router->get('/', [Home::class, 'indexAction']);
$router->get('/about', [About::class, 'indexAction']);
$router->get('/app', [App::class, 'indexAction']);

// Login and account
$router->get('/login', [Login::class, 'newAction']);
$router->get('/login/new', [Login::class, 'newAction']);
$router->post('/login/create', [Login::class, 'createAction']);
$router->post('/login/guest', [Login::class, 'guestAction']);
$router->post('/logout', [Login::class, 'destroyAction']);
$router->get('/login/show-logout-message', [Login::class, 'showLogoutMessageAction']);
$router->get('/account/validate-email', [Account::class, 'validateEmailAction']);

// Signup; activation is a GET, as people open it from an email
$router->get('/signup/new', [Signup::class, 'newAction']);
$router->post('/signup/create', [Signup::class, 'createAction']);
$router->get('/signup/success', [Signup::class, 'successAction']);
$router->get('/signup/activate/{token:[\da-f]+}', [Signup::class, 'activateAction']);
$router->get('/signup/activated', [Signup::class, 'activatedAction']);

// Password reset
$router->get('/password/forgot', [Password::class, 'forgotAction']);
$router->post('/password/request-reset', [Password::class, 'requestResetAction']);
$router->get('/password/reset/{token:[\da-f]+}', [Password::class, 'resetAction']);
$router->post('/password/reset-password', [Password::class, 'resetPasswordAction']);

// Profile and avatar
$router->get('/profile/show', [Profile::class, 'showAction']);
$router->post('/profile/update', [Profile::class, 'updateAction']);
$router->post('/profile/update-avatar', [Profile::class, 'updateAvatarAction']);
$router->post('/profile/remove-avatar', [Profile::class, 'removeAvatarAction']);
$router->post('/profile/update-location-sharing', [Profile::class, 'updateLocationSharingAction']);
$router->get('/avatar/{id:\d+}', [Avatar::class, 'showAction']);

// Chat and map
$router->post('/app/get', [App::class, 'getAction']);
$router->post('/app/get-older', [App::class, 'getOlderAction']);
$router->post('/app/send', [App::class, 'sendAction']);
$router->post('/app/get-people', [App::class, 'getPeopleAction']);
$router->post('/app/get-locations', [App::class, 'getLocationsAction']);

// Older URLs, from links and callers made before each route was listed here
$router->get('/home/index', [Home::class, 'indexAction']);
$router->get('/about/index', [About::class, 'indexAction']);
$router->get('/app/index', [App::class, 'indexAction']);
$router->post('/login/destroy', [Login::class, 'destroyAction']);

$router->dispatch($_SERVER['REQUEST_METHOD'], $_SERVER['REQUEST_URI']);

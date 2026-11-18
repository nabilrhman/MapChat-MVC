<?php

use App\Config;
use App\Controllers\About;
use App\Controllers\Account;
use App\Controllers\Avatar;
use App\Controllers\Google;
use App\Controllers\Home;
use App\Controllers\Login;
use App\Controllers\Password;
use App\Controllers\Privacy;
use App\Controllers\Profile;
use App\Controllers\Push;
use App\Controllers\Room;
use App\Controllers\Signup;

/**
 * Front controller: every request that isn't a file in public/ comes here
 * and goes to the action its route names
 */

require dirname(__DIR__) . '/vendor/autoload.php';

error_reporting(E_ALL);
set_error_handler(Core\Error::errorHandler(...));
set_exception_handler(Core\Error::exceptionHandler(...));

// In the database, so restarting the container doesn't log anyone out. A
// session lasts a week without activity
session_set_save_handler(new Core\DatabaseSessionHandler(), true);
session_start([
    'gc_maxlifetime' => 60 * 60 * 24 * 7,
    // Refuse session ids the server didn't create
    'use_strict_mode' => true,
    'cookie_httponly' => true,
    'cookie_samesite' => 'Lax',
    'cookie_secure' => str_starts_with(Config::appUrl(), 'https://'),
]);

Core\ContentSecurityPolicy::send();

$router = new Core\Router();

// Each route names its method and the exact action it runs; no other method
// of a controller can be reached from a URL

// Pages
$router->get('/', [Home::class, 'indexAction']);
$router->get('/about', [About::class, 'indexAction']);
$router->get('/privacy', [Privacy::class, 'indexAction']);
$router->get('/app', [Room::class, 'indexAction']);

// Login and account
$router->get('/login', [Login::class, 'newAction']);
$router->get('/login/new', [Login::class, 'newAction']);
$router->post('/login/create', [Login::class, 'createAction']);
$router->post('/login/guest', [Login::class, 'guestAction']);
$router->post('/logout', [Login::class, 'destroyAction']);
$router->get('/login/show-logout-message', [Login::class, 'showLogoutMessageAction']);
$router->get('/login/show-deleted-message', [Login::class, 'showDeletedMessageAction']);
// Signing in with Google, which makes the account the first time
$router->get('/login/google', [Google::class, 'startAction']);
$router->get('/login/google/callback', [Google::class, 'callbackAction']);
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
$router->post('/profile/delete', [Profile::class, 'deleteAction']);
$router->get('/avatar/{id:\d+}', [Avatar::class, 'showAction']);

// Chat and map
$router->post('/app/get', [Room::class, 'getAction']);
$router->post('/app/get-older', [Room::class, 'getOlderAction']);
$router->post('/app/send', [Room::class, 'sendAction']);
$router->post('/app/transcribe', [Room::class, 'transcribeAction']);
$router->post('/app/mark-read', [Room::class, 'markReadAction']);
$router->post('/app/get-people', [Room::class, 'getPeopleAction']);
$router->post('/app/get-locations', [Room::class, 'getLocationsAction']);

// Web Push for this browser (public/js/notify/notifications.js)
$router->post('/push/subscribe', [Push::class, 'subscribeAction']);
$router->post('/push/unsubscribe', [Push::class, 'unsubscribeAction']);

// Older URLs, from links and callers made before each route was listed here
$router->get('/home/index', [Home::class, 'indexAction']);
$router->get('/about/index', [About::class, 'indexAction']);
$router->get('/app/index', [Room::class, 'indexAction']);
$router->post('/login/destroy', [Login::class, 'destroyAction']);

$router->dispatch($_SERVER['REQUEST_METHOD'], $_SERVER['REQUEST_URI']);

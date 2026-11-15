<?php

namespace App;

use App\Models\User;
use App\Models\RememberedLogin;

/**
 * Authentication
 */
class Auth
{
    /**
     * Login the user
     *
     * @param User $user The user model
     * @param boolean $remember_me Remember the login if true
     *
     * @return void
     */
    public static function login($user, $remember_me)
    {
        session_regenerate_id(true);

        $_SESSION['user_id'] = $user->id;

        if ($remember_me && $user->rememberLogin()) {
            static::setRememberCookie($user->remember_token, $user->expiry_timestamp);
        }
    }

    /**
     * Logout the user
     *
     * @return void
     */
    public static function logout()
    {
        // Unset all of the session variables
        $_SESSION = [];

        // Delete the session cookie
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();

            setcookie(
                session_name(),
                '',
                time() - 42000,
                $params['path'],
                $params['domain'],
                $params['secure'],
                $params['httponly']
            );
        }

        // Finally destroy the session
        session_destroy();

        static::forgetLogin();
    }

    /**
     * Remember the originally-requested page in the session
     *
     * @return void
     */
    public static function rememberRequestedPage()
    {
        $_SESSION['return_to'] = $_SERVER['REQUEST_URI'];
    }

    /**
     * Set or clear the remember-me cookie, with the session cookie's rules
     *
     * @param string $value
     * @param int $expires
     *
     * @return void
     */
    protected static function setRememberCookie($value, $expires)
    {
        setcookie('remember_me', $value, [
            'expires' => $expires,
            'path' => '/',
            'httponly' => true,
            'samesite' => 'Lax',
            'secure' => str_starts_with(Config::appUrl(), 'https://'),
        ]);
    }

    /**
     * Get the originally-requested page to return to after requiring login, or default to the homepage
     *
     * @return string
     */
    public static function getReturnToPage()
    {
        $page = $_SESSION['return_to'] ?? '/';
        unset($_SESSION['return_to']);

        // Paths on this site only
        if (!str_starts_with($page, '/') || str_starts_with($page, '//') || str_starts_with($page, '/\\')) {
            return '/';
        }

        return $page;
    }

    /**
     * Get the current logged-in user, from the session or the remember-me cookie
     *
     * @return mixed The user model or null if not logged in
     */
    public static function getUser()
    {
        // Asked for many times per request; each lookup is a database round trip
        static $user = false;

        // Look again if someone logged in or out since
        if ($user === false || $user?->id != ($_SESSION['user_id'] ?? null)) {
            $user = isset($_SESSION['user_id'])
                ? User::findByID($_SESSION['user_id'])
                : static::loginFromRememberCookie();
        }

        return $user;
    }

    /**
     * Get the logged-in user's email
     *
     * @return mixed The user email or null if not logged in
     */
    public static function getEmail()
    {
        return static::getUser()?->email;
    }

    /**
     * Login the user from a remembered login cookie
     *
     * @return mixed The user model if login cookie found; null otherwise
     */
    protected static function loginFromRememberCookie()
    {
        $cookie = $_COOKIE['remember_me'] ?? false;

        if ($cookie) {
            $remembered_login = RememberedLogin::findByToken($cookie);

            if ($remembered_login && !$remembered_login->hasExpired()) {
                $user = $remembered_login->getUser();

                static::login($user, false);

                return $user;
            }
        }

        return null;
    }

    /**
     * Forget the remembered login, if present
     *
     * @return void
     */
    protected static function forgetLogin()
    {
        $cookie = $_COOKIE['remember_me'] ?? false;

        if ($cookie) {
            $remembered_login = RememberedLogin::findByToken($cookie);

            if ($remembered_login) {
                $remembered_login->delete();
            }

            static::setRememberCookie('', time() - 3600);  // expired, so the browser drops it
        }
    }
}

<?php

namespace App\SignIn;

use App\Config;
use App\Models\RememberedLogin;
use App\Models\User;

/**
 * Who is logged in: the session, the remember-me cookie, and a guest's
 * cookie that brings them back to their account
 */
class Auth
{
    /** How long "Remember me" keeps someone logged in, in seconds */
    const REMEMBER_SECONDS = 60 * 60 * 24 * 30;

    /**
     * Log the user in on this browser
     *
     * @param bool $isRemembered Keep them logged in after the browser closes
     */
    public static function login(User $user, bool $isRemembered): void
    {
        session_regenerate_id(true);

        $_SESSION['user_id'] = $user->id;

        if ($isRemembered) {
            $expiresAt = time() + static::REMEMBER_SECONDS;
            $token = RememberedLogin::create($user->id, $expiresAt);
            static::setCookie('remember_me', $token, $expiresAt);
        }
    }

    /**
     * Log out on this browser: the session, its cookie and any remembered login
     */
    public static function logout(): void
    {
        $_SESSION = [];

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

        session_destroy();

        static::forgetLogin();
    }

    /**
     * Remember the page asked for, to come back to after logging in
     */
    public static function rememberRequestedPage(): void
    {
        $_SESSION['return_to'] = $_SERVER['REQUEST_URI'];
    }

    /**
     * Keep a guest's token in their browser until the account is deleted, so
     * "Try it as a guest" brings them back to it
     *
     * @param User $guest A guest just created by User::createGuest
     */
    public static function rememberGuest(User $guest): void
    {
        static::setCookie('guest_token', $guest->guestToken, $guest->guestExpiresAt());
    }

    /**
     * Set a cookie with the session cookie's rules
     *
     * @param int $expires Unix time
     */
    protected static function setCookie(string $name, string $value, int $expires): void
    {
        setcookie($name, $value, [
            'expires' => $expires,
            'path' => '/',
            'httponly' => true,
            'samesite' => 'Lax',
            'secure' => str_starts_with(Config::appUrl(), 'https://'),
        ]);
    }

    /**
     * The page to go to after logging in: the one asked for, or the home page
     */
    public static function getReturnToPage(): string
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
     * The logged-in user, from the session or the remember-me cookie. A
     * guest whose day is up counts as logged out
     */
    public static function getUser(): ?User
    {
        // Asked for many times per request; each lookup is a database round trip
        static $user = false;

        $sessionUserId = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null;

        // Look again if someone logged in or out since
        if ($user === false || $user?->id !== $sessionUserId) {
            $user = $sessionUserId !== null
                ? User::findByID($sessionUserId)
                : static::loginFromRememberCookie();
        }

        return $user && !$user->isExpiredGuest() ? $user : null;
    }

    /**
     * The logged-in user's email, or null
     */
    public static function getEmail(): ?string
    {
        return static::getUser()?->email;
    }

    /**
     * Log in from the remember-me cookie, if it's still good
     */
    protected static function loginFromRememberCookie(): ?User
    {
        $rememberedLogin = RememberedLogin::findByToken($_COOKIE['remember_me'] ?? '');
        if (!$rememberedLogin || $rememberedLogin->hasExpired()) {
            return null;
        }

        $user = User::findByID($rememberedLogin->user_id);
        if ($user) {
            static::login($user, isRemembered: false);
        }

        return $user;
    }

    /**
     * Forget this browser's remembered login, if it has one
     */
    protected static function forgetLogin(): void
    {
        $cookie = $_COOKIE['remember_me'] ?? '';
        if ($cookie === '') {
            return;
        }

        RememberedLogin::findByToken($cookie)?->delete();

        // Expired, so the browser drops it
        static::setCookie('remember_me', '', time() - 3600);
    }
}

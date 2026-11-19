<?php

namespace App\Controllers;

use App\Flash;
use App\Models\PushSubscription;
use App\Models\User;
use App\Models\Whereabouts;
use App\SignIn\Auth;
use Core\View;

/**
 * Logging in, as yourself or as a guest, and logging out
 */
class Login extends \Core\Controller
{
    /**
     * Show the login page
     */
    public function newAction(): void
    {
        View::renderTemplate('Login/new.html');
    }

    /**
     * Log in with an email and password
     */
    public function createAction(): void
    {
        $isRemembered = isset($_POST['remember_me']);
        $email = $this->stringFrom($_POST, 'email');
        $user = User::authenticate($email, $this->stringFrom($_POST, 'password'));

        // These say only that the email has an account, as signing up already does
        $account = $user ? null : User::findByEmail($email);
        $isLocked = $account?->isLoginLocked() === true;
        $isGoogleOnly = $account?->hasPassword() === false;

        if ($isLocked) {
            Flash::addMessage('Too many wrong passwords. Try again in ' . User::FAILED_LOGIN_MINUTES . ' minutes, or reset your password.', Flash::WARNING);
        } elseif ($isGoogleOnly) {
            Flash::addMessage('This account signs in with Google. Use Continue with Google, or set a password with Forgot password.', Flash::WARNING);
        } elseif (!$user) {
            Flash::addMessage('Wrong email or password.', Flash::WARNING);
        } elseif (!$user->is_active) {
            Flash::addMessage('Activate your account first. Check your email for the link.', Flash::WARNING);
        } else {
            Auth::login($user, $isRemembered);

            Flash::addMessage('Logged in.');

            Whereabouts::locate($user->email, Whereabouts::getRealIP());

            $this->redirect(Auth::getReturnToPage());
        }

        View::renderTemplate('Login/new.html', [
            'email' => $email,
            'remember_me' => $isRemembered
        ]);
    }

    /**
     * Sign in with a new throwaway guest account (POST only, so link
     * previews and crawlers can't create accounts)
     */
    public function guestAction(): void
    {
        // Someone who was a guest in this browser gets that account back
        $user = User::findGuestByToken($this->stringFrom($_COOKIE, 'guest_token'));

        if ($user) {
            Flash::addMessage('Welcome back, ' . $user->name . '.', Flash::INFO);
        } else {
            $user = User::createGuest();
            Auth::rememberGuest($user);
            Flash::addMessage('Signed in as ' . $user->name . '.', Flash::INFO);
        }

        Auth::login($user, isRemembered: false);
        Whereabouts::locate($user->email, Whereabouts::getRealIP());

        $this->redirect('/app');
    }

    /**
     * Log out (POST only, so a link or image on another site can't sign
     * anyone out). The page sends this browser's push address along, if it
     * has one, so its notifications stop with the login
     */
    public function destroyAction(): void
    {
        $email = Auth::getEmail();
        $pushEndpoint = $this->stringFrom($_POST, 'push_endpoint');
        if ($email !== null && $pushEndpoint !== '') {
            PushSubscription::delete($email, $pushEndpoint);
        }

        Auth::logout();

        $this->redirect('/login/show-logout-message');
    }

    /**
     * Show "Logged out" on the login page. It's a request of its own because
     * logging out destroyed the session the message is kept in
     */
    public function showLogoutMessageAction(): void
    {
        Flash::addMessage('Logged out.');

        $this->redirect('/login');
    }

    /**
     * Say the profile is deleted, on the home page. A request of its own for
     * the same reason as the logout message
     */
    public function showDeletedMessageAction(): void
    {
        Flash::addMessage('Your profile is deleted.');

        $this->redirect('/');
    }
}

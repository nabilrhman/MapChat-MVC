<?php

namespace App\Controllers;

use App\Models\UserInfo;
use Core\View;
use App\Models\User;
use App\Auth;
use App\Flash;

/**
 * Login controller
 */
class Login extends \Core\Controller
{
    /**
     * Show the login page
     *
     * @return void
     */
    public function newAction()
    {
        View::renderTemplate('Login/new.html');
    }

    /**
     * Log in a user
     *
     * @return void
     */
    public function createAction()
    {
        $remember_me = isset($_POST['remember_me']);
        $user = User::authenticate($_POST['email'] ?? '', $_POST['password'] ?? '');

        if (!$user) {
            Flash::addMessage('Wrong email or password', Flash::WARNING);
        } elseif (!$user->is_active) {
            Flash::addMessage('Activate your account first. Check your email for the link.', Flash::WARNING);
        } else {
            Auth::login($user, $remember_me);

            Flash::addMessage('Logged in');

            UserInfo::locate($user->email, UserInfo::getRealIP());

            $this->redirect(Auth::getReturnToPage());
        }

        View::renderTemplate('Login/new.html', [
            'email' => $_POST['email'] ?? '',
            'remember_me' => $remember_me
        ]);
    }

    /**
     * Sign in with a new throwaway guest account (POST only, so link
     * previews and crawlers can't create accounts)
     *
     * @return void
     */
    public function guestAction()
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->redirect('/login');
        }

        // Someone who was a guest in this browser gets that account back
        $user = User::findGuestByToken($_COOKIE['guest_token'] ?? '');

        if ($user) {
            Flash::addMessage('Welcome back, ' . $user->name, Flash::INFO);
        } else {
            $user = User::createGuest();
            Auth::rememberGuest($user);
            Flash::addMessage('Signed in as ' . $user->name, Flash::INFO);
        }

        Auth::login($user, false);
        UserInfo::locate($user->email, UserInfo::getRealIP());

        $this->redirect('/app');
    }

    /**
     * Log out a user
     *
     * @return void
     */
    public function destroyAction()
    {
        Auth::logout();

        $this->redirect('/login/show-logout-message');
    }

    /**
     * Show a "logged out" flash message and redirect to the homepage. Necessary to use the flash messages
     * as they use the session and at the end of the logout method (destroyAction) the session is destroyed
     * so a new action needs to be called in order to use the session.
     *
     * @return void
     */
    public function showLogoutMessageAction()
    {
        Flash::addMessage('Logged out');

        $this->redirect('/login');
    }
}

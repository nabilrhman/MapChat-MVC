<?php

namespace App\Controllers;

use App\Config;
use App\Models\UserInfo;
use \Core\View;
use \App\Models\User;
use \App\Auth;
use \App\Flash;

/**
 * Login controller
 *
 * PHP version 7.0
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

        if (!empty($_POST['email']) && !empty($_POST['password']))
        {
            $user = User::authenticate($_POST['email'], $_POST['password']);

            if ($user)
            {
                if (!$user->is_active)
                {
                    Flash::addMessage('Activate your account first. Check your email for the link.', Flash::WARNING);
                }
                else
                {
                    Auth::login($user, $remember_me);

                    Flash::addMessage('Logged in');

                    $this->saveLocation($user);

                    $this->redirect(Auth::getReturnToPage());
                }
            }
            else
            {
                Flash::addMessage('Wrong email or password', Flash::WARNING);
            }
        }
        else
        {
            Flash::addMessage('Wrong email or password', Flash::WARNING);
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

        $user = User::createGuest();
        Auth::login($user, false);
        $this->saveLocation($user);

        Flash::addMessage('Signed in as ' . $user->name, Flash::INFO);
        $this->redirect('/app');
    }

    /**
     * Look up and save where the user is signing in from
     *
     * @param User $user
     *
     * @return void
     */
    protected function saveLocation($user)
    {
        $userInfo = new UserInfo(['email' => $user->email, 'ip' => UserInfo::getRealIP()]);

        $userInfo->saveIP();
        $userInfo->findLocationFromIP();
        $userInfo->saveLocation();
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
        //Added by NR
        //$this->redirect('/login');
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

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
        if(isset($_POST['email']) && $_POST['password'])
        {
            $user = User::authenticate($_POST['email'], $_POST['password']);
            $remember_me = isset($_POST['remember_me']);

            if ($user)
            {
                if (!$user->is_active)
                {
                    Flash::addMessage('Account not activated, please check your email', Flash::WARNING);
                }
                else
                {
                    Auth::login($user, $remember_me);

                    Flash::addMessage('Login successful');

                    $_POST['email'] = $user->email;
                    $_POST['ip'] = UserInfo::getRealIP();

                    $userInfo = new UserInfo($_POST);

                    $userInfo->saveIP();
                    $userInfo->findLocationFromIP();
                    $userInfo->saveLocation();

                    /** Bugfix: Since AJAX keeps calling /app/get for chat sync, ignore if requested to return to the AJAX request page (same page) */
                    if (Auth::getReturnToPage() == '/app/get')
                    {
                        $this->redirect('/app');
                    }
                    else
                    {
                        $this->redirect(Auth::getReturnToPage());
                    }


                }
            }
            else
            {
                Flash::addMessage('Incorrect username/password', Flash::WARNING);
            }
        }
        else
        {
            Flash::addMessage('Incorrect username/password', Flash::WARNING);
        }


        View::renderTemplate('Login/new.html', [
            'email' => $_POST['email'],
            'remember_me' => $remember_me
        ]);
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
        Flash::addMessage('Logout successful');

        $this->redirect('/login');
    }
}

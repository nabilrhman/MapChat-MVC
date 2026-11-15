<?php

namespace App\Controllers;

use \Core\View;
use \App\Models\User;
use \App\Flash;

/**
 * Signup controller
 *
 * PHP version 7.0
 */
class Signup extends \Core\Controller
{

    /**
     * Show the signup page
     *
     * @return void
     */
    public function newAction()
    {
        View::renderTemplate('Signup/new.html');
    }

    /**
     * Sign up a new user
     *
     * @return void
     */
    public function createAction()
    {
        // Only the form's fields
        $user = new User([
            'name' => $_POST['name'] ?? '',
            'email' => $_POST['email'] ?? '',
            'password' => $_POST['password'] ?? '',
        ]);

        if ($user->save()) {

            if (\App\Config::mailEnabled()) {
                $user->sendActivationEmail();
                $this->redirect('/signup/success');
            }

            $this->redirect('/signup/activated');

        } else {

            View::renderTemplate('Signup/new.html', [
                'user' => $user
            ]);

        }
    }

    /**
     * Show the signup success page
     *
     * @return void
     */
    public function successAction()
    {

        Flash::addMessage('Account created. Check your email to activate it.', Flash::SUCCESS);
        $this->redirect('/login');
    }

    /**
     * Activate a new account
     *
     * @return void
     */
    public function activateAction()
    {
        if (!User::activate($this->route_params['token'])) {
            Flash::addMessage('That activation link is wrong or was already used. Try logging in.', Flash::WARNING);
            $this->redirect('/login');
        }

        $this->redirect('/signup/activated');
    }

    /**
     * Show the activation success page
     *
     * @return void
     */
    public function activatedAction()
    {
        Flash::addMessage('Your account is active. Log in to start chatting.', Flash::SUCCESS);
        $this->redirect('/login');
    }
}

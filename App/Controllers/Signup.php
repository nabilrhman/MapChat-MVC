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
        $user = new User($_POST);

        if ($user->save()) {

            $user->sendActivationEmail();

            $this->redirect('/signup/success');

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

        Flash::addMessage('Success! Please check your email to activate', Flash::SUCCESS);
        $this->redirect('/login');
    }

    /**
     * Activate a new account
     *
     * @return void
     */
    public function activateAction()
    {
        User::activate($this->route_params['token']);

        $this->redirect('/signup/activated');
    }

    /**
     * Get the name of the account from token
     *
     * @return the name of the account
     */
    public function getNameAction()
    {
        return User::getNameFromToken($this->route_params['token']);
    }


    /**
     * Show the activation success page
     *
     * @return void
     */
    public function activatedAction()
    {
        Flash::addMessage('Success! Your account is now active', Flash::SUCCESS);
        $this->redirect('/login');
    }
}

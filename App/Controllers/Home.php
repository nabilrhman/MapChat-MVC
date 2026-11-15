<?php

namespace App\Controllers;

use App\Auth;

/**
 * Home controller
 */
class Home extends \Core\Controller
{
    /**
     * Send people to the chat if they're logged in, otherwise to the login page
     *
     * @return void
     */
    public function indexAction()
    {
        if (Auth::getUser()) {
            $this->redirect('/app');
        } else {
            $this->redirect('/login');
        }
    }
}

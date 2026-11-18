<?php

namespace App\Controllers;

use App\SignIn\Auth;

/**
 * The site's address, which leads to the chat or the login page
 */
class Home extends \Core\Controller
{
    /**
     * Send people to the chat if they're logged in, otherwise to the login page
     */
    public function indexAction(): void
    {
        $this->redirect(Auth::getUser() ? '/app' : '/login');
    }
}

<?php

namespace App\Controllers;

use App\Flash;
use \Core\View;
use \App\Auth;

/**
 * Home controller
 *
 * PHP version 7.0
 */
class Home extends \Core\Controller
{

    /**
     * Show the index page
     *
     * @return void
     */
    public function indexAction()
    {
        if(Auth::getUser())
        {
            $this->redirect('/app');
        }
        else
        {
            $this->redirect('/login');
        }
        //View::renderTemplate('Home/index.html');
    }
}

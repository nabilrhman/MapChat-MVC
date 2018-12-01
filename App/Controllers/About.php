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
class About extends \Core\Controller
{

    /**
     * Show the index page
     *
     * @return void
     */
    public function indexAction()
    {

        View::renderTemplate('About/index.html');
    }
}

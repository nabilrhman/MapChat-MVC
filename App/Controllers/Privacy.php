<?php

namespace App\Controllers;

use Core\View;

/**
 * The privacy policy, open to everyone: Google's sign-in screen links to it
 */
class Privacy extends \Core\Controller
{
    /**
     * Show the privacy policy
     */
    public function indexAction(): void
    {
        View::renderTemplate('Privacy/index.html');
    }
}

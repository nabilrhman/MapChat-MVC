<?php

namespace App\Controllers;

use Core\View;

/**
 * The About page
 */
class About extends \Core\Controller
{
    /**
     * Show the About page
     */
    public function indexAction(): void
    {
        View::renderTemplate('About/index.html');
    }
}

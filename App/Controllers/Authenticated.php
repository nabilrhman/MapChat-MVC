<?php

namespace App\Controllers;

/**
 * A controller whose every action needs someone logged in
 */
abstract class Authenticated extends \Core\Controller
{
    /**
     * Send anyone not logged in to the login page first
     */
    protected function before(): ?bool
    {
        $this->requireLogin();

        return null;
    }
}

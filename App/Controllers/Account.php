<?php

namespace App\Controllers;

use App\Models\User;

/**
 * Account controller
 */
class Account extends \Core\Controller
{
    /**
     * Validate if email is available (AJAX) for a new signup or an existing user.
     * The ID of an existing user can be passed in in the querystring to ignore when
     * checking if an email already exists or not.
     *
     * @return void
     */
    public function validateEmailAction()
    {
        $this->json(!User::emailExists($_GET['email'] ?? '', $_GET['ignore_id'] ?? null));
    }
}

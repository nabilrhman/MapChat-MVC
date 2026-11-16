<?php

namespace App\Controllers;

use App\Models\User;

/**
 * Checks the sign-up and profile forms make while you type
 */
class Account extends \Core\Controller
{
    /**
     * Answer whether an email is free to use (for AJAX): true or false. The
     * profile form passes ignore_id, its own user's id, so keeping your own
     * email isn't a clash
     */
    public function validateEmailAction(): void
    {
        $ignoreId = isset($_GET['ignore_id']) ? (int) $_GET['ignore_id'] : null;

        $this->json(!User::isEmailTaken((string) ($_GET['email'] ?? ''), $ignoreId));
    }
}

<?php

namespace App\Controllers;

use App\Models\User;

/**
 * Checks the sign-up and profile forms make while you type
 */
class Account extends \Core\Controller
{
    /**
     * Answer whether an email is free to use (for AJAX): true or false. One
     * at MapChat's own domain never is. The profile form passes ignore_id,
     * its own user's id, so keeping your own email isn't a clash. A post, so
     * the email stays out of the address and the access logs
     */
    public function checkEmailAction(): void
    {
        $ignoreId = isset($_POST['ignore_id']) ? (int) $_POST['ignore_id'] : null;
        $email = $this->stringFrom($_POST, 'email');

        $owner = User::findByEmail($email);
        // A guest keeps their placeholder at MapChat's domain; nobody may take a new one
        $isOwnEmail = $owner !== null && $owner->id === $ignoreId;
        $isFree = $isOwnEmail || ($owner === null && !User::isReservedEmail($email));

        $this->json($isFree);
    }
}

<?php

namespace App\Controllers;

use App\Models\Characters;
use App\Models\User;

/**
 * Drawn avatars for people without an uploaded picture
 */
class Avatar extends Authenticated
{
    /**
     * Send the drawn avatar of the user in the route, as SVG
     */
    public function showAction(): void
    {
        $user = User::findByID((int) $this->routeParams['id']);

        if (!$user) {
            throw new \Exception('No such user', 404);
        }

        header('Content-Type: image/svg+xml');
        // The URL carries the name, so a renamed user gets a fresh image
        header('Cache-Control: private, max-age=86400');
        echo \App\Avatar::drawn($user->id, $user->name, Characters::isDemo($user->email));
    }
}

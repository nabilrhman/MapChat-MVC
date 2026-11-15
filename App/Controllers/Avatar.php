<?php

namespace App\Controllers;

use App\Models\User;

/**
 * Initials avatars for people without an uploaded picture
 */
class Avatar extends Authenticated
{
    /**
     * Send the avatar of the user in the route, as SVG
     *
     * @return void
     */
    public function showAction()
    {
        $user = User::findByID($this->route_params['id']);

        if (!$user) {
            throw new \Exception('No such user', 404);
        }

        header('Content-Type: image/svg+xml');
        // The URL carries the name, so a renamed user gets a fresh image
        header('Cache-Control: private, max-age=86400');
        echo str_ends_with($user->email, \App\Models\DemoChat::DOMAIN)
            ? \App\Avatar::face($user->id, $user->name)
            : \App\Avatar::svg($user->name, $user->id);
    }
}

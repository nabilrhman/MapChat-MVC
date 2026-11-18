<?php

namespace App\Controllers;

use App\Models\PushSubscription;
use App\SignIn\Auth;

/**
 * Turning Web Push on and off for this browser (public/js/notifications.js)
 */
class Push extends Authenticated
{
    /** Longest push service address a browser gives, with room to spare */
    const MAX_ENDPOINT_LENGTH = 2000;

    /**
     * Save this browser's subscription for the signed-in user (for AJAX)
     */
    public function subscribeAction(): void
    {
        $endpoint = (string) ($_POST['endpoint'] ?? '');
        $p256dh = (string) ($_POST['p256dh'] ?? '');
        $auth = (string) ($_POST['auth'] ?? '');

        // Only push services' own https addresses; never one of ours or a local one
        if (!static::isPushEndpoint($endpoint) || $p256dh === '' || $auth === ''
            || strlen($p256dh) > 255 || strlen($auth) > 255) {
            $this->json(['error' => 'Not a push subscription.'], 400);
            return;
        }

        PushSubscription::save(Auth::getEmail(), $endpoint, $p256dh, $auth);
        $this->json(['subscribed' => true]);
    }

    /**
     * Forget this browser for the signed-in user (for AJAX)
     */
    public function unsubscribeAction(): void
    {
        PushSubscription::delete(Auth::getEmail(), (string) ($_POST['endpoint'] ?? ''));
        $this->json(['subscribed' => false]);
    }

    /**
     * Whether this looks like a push service's address: https, with a host
     * that isn't an IP address
     */
    public static function isPushEndpoint(string $endpoint): bool
    {
        if (strlen($endpoint) > static::MAX_ENDPOINT_LENGTH || parse_url($endpoint, PHP_URL_SCHEME) !== 'https') {
            return false;
        }
        $host = parse_url($endpoint, PHP_URL_HOST);

        return is_string($host) && str_contains($host, '.') && filter_var($host, FILTER_VALIDATE_IP) === false;
    }
}

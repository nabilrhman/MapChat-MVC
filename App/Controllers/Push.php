<?php

namespace App\Controllers;

use App\Models\PushSubscription;
use App\SignIn\Auth;

/**
 * Turning Web Push on and off for this browser (public/js/notify/notifications.js)
 */
class Push extends Authenticated
{
    /** Longest push service address a browser gives, with room to spare */
    const MAX_ENDPOINT_LENGTH = 2000;

    /** The push services browsers subscribe with, by their exact host: Chrome's, Firefox's */
    const PUSH_SERVICE_HOSTS = ['fcm.googleapis.com', 'updates.push.services.mozilla.com'];

    /** Push services whose hosts vary, by the domain they all end in: Safari's, Edge's */
    const PUSH_SERVICE_DOMAINS = ['push.apple.com', 'notify.windows.com'];

    /**
     * Save this browser's subscription for the signed-in user (for AJAX)
     */
    public function subscribeAction(): void
    {
        $endpoint = (string) ($_POST['endpoint'] ?? '');
        $p256dh = (string) ($_POST['p256dh'] ?? '');
        $auth = (string) ($_POST['auth'] ?? '');

        // The chat worker posts to it, so only a real push service's address
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
     * Whether this is a known push service's https address. Only these: any
     * other host, even a public name, could point the chat worker at an
     * address inside our network
     */
    public static function isPushEndpoint(string $endpoint): bool
    {
        if (strlen($endpoint) > static::MAX_ENDPOINT_LENGTH) {
            return false;
        }

        $parts = parse_url($endpoint);
        // A port or a login (https://evil\@fcm…) can make an HTTP client go elsewhere
        if ($parts === false || ($parts['scheme'] ?? '') !== 'https'
            || isset($parts['port']) || isset($parts['user']) || isset($parts['pass'])) {
            return false;
        }

        $host = strtolower($parts['host'] ?? '');
        if (in_array($host, static::PUSH_SERVICE_HOSTS, strict: true)) {
            return true;
        }
        foreach (static::PUSH_SERVICE_DOMAINS as $domain) {
            if (str_ends_with($host, ".$domain")) {
                return true;
            }
        }

        return false;
    }
}

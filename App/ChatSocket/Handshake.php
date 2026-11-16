<?php

namespace App\ChatSocket;

use Amp\Http\HttpStatus;
use Amp\Http\Server\Request;
use Amp\Http\Server\Response;
use Amp\Websocket\Server\Rfc6455Acceptor;
use Amp\Websocket\Server\WebsocketAcceptor;
use App\Models\User;
use Core\SameOrigin;

/**
 * Lets a page's socket in only from this site, with a logged-in session.
 * WebSockets aren't covered by the same-origin rule, so another site's page
 * could otherwise open one with the visitor's cookie
 */
class Handshake implements WebsocketAcceptor
{
    /** The path Apache passes to the chat socket */
    const PATH = '/ws';

    /** Open chat pages one person may have at once, and in all */
    const MAX_TABS_PER_USER = 20;
    const MAX_TABS = 2000;

    /**
     * Check pages against the Hub's open pages
     */
    public function __construct(private readonly Hub $hub)
    {
    }

    /**
     * Let the page in, or refuse it with a status that says why
     */
    public function handleHandshake(Request $request): Response
    {
        if ($request->getUri()->getPath() !== static::PATH) {
            return static::refusal(HttpStatus::NOT_FOUND);
        }

        // Apache keeps the Host header for us, and the host's proxy says whether it was https
        $isHttps = $request->getHeader('x-forwarded-proto') === 'https';
        if (!SameOrigin::isFromThisSite($request->getHeader('origin'), $request->getHeader('host'), $isHttps)) {
            return static::refusal(HttpStatus::FORBIDDEN);
        }

        $sessionId = $request->getCookie(session_name())?->getValue() ?? '';
        $userId = Sessions::getUserId($sessionId);
        $user = $userId === null ? null : User::findByID($userId);
        if (!$user || $user->isExpiredGuest()) {
            return static::refusal(HttpStatus::UNAUTHORIZED);
        }

        if ($this->hub->countTabs() >= static::MAX_TABS) {
            return static::refusal(HttpStatus::SERVICE_UNAVAILABLE);
        }
        if ($this->hub->countTabsOf($user->email) >= static::MAX_TABS_PER_USER) {
            return static::refusal(HttpStatus::TOO_MANY_REQUESTS);
        }

        $request->setAttribute(Hub::class, [$user, $sessionId]);

        return (new Rfc6455Acceptor())->handleHandshake($request);
    }

    /**
     * A plain-text refusal that closes the connection
     *
     * @param int $status An HTTP status
     */
    private static function refusal(int $status): Response
    {
        return new Response($status, ['content-type' => 'text/plain', 'connection' => 'close'], HttpStatus::getReason($status));
    }
}

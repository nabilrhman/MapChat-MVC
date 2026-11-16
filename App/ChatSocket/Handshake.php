<?php

namespace App\ChatSocket;

use Amp\Http\HttpStatus;
use Amp\Http\Server\Request;
use Amp\Http\Server\Response;
use Amp\Websocket\Server\Rfc6455Acceptor;
use Amp\Websocket\Server\WebsocketAcceptor;
use App\Config;
use App\Models\User;

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

    private Hub $hub;

    public function __construct(Hub $hub)
    {
        $this->hub = $hub;
    }

    public function handleHandshake(Request $request): Response
    {
        if ($request->getUri()->getPath() !== static::PATH) {
            return static::refusal(HttpStatus::NOT_FOUND);
        }

        if (!static::isFromThisSite($request->getHeader('origin'), $request->getHeader('host'))) {
            return static::refusal(HttpStatus::FORBIDDEN);
        }

        $sessionId = $request->getCookie(session_name())?->getValue() ?? '';
        $userId = Sessions::getUserId($sessionId);
        $user = $userId === null ? null : User::findByID($userId);
        if (!$user) {
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
     * Whether the page asking is this site's: its origin is APP_URL, or the
     * address the request came to (Apache keeps the Host header for us)
     *
     * @param string|null $origin
     * @param string|null $host
     *
     * @return bool
     */
    public static function isFromThisSite($origin, $host)
    {
        if ($origin === null || $origin === '') {
            return false;
        }

        $appUrl = Config::get('APP_URL');
        if ($appUrl !== null && rtrim($appUrl, '/') === $origin) {
            return true;
        }

        $originHost = parse_url($origin, PHP_URL_HOST);
        $originPort = parse_url($origin, PHP_URL_PORT);
        $originAddress = $originHost . ($originPort ? ":$originPort" : '');

        return $originHost !== null && $host !== null && strcasecmp($originAddress, $host) === 0;
    }

    /**
     * @param int $status
     *
     * @return Response
     */
    private static function refusal($status)
    {
        return new Response($status, ['content-type' => 'text/plain', 'connection' => 'close'], HttpStatus::getReason($status));
    }
}

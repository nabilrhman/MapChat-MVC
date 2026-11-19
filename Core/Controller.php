<?php

namespace Core;

use App\Config;
use App\Flash;
use App\SignIn\Auth;

/**
 * What every controller shares: the route's values, the origin check, the
 * before and after filters, and replies
 */
abstract class Controller
{
    /**
     * Store the matched route's values
     *
     * @param array<string, string> $routeParams The route's variables, e.g. ['id' => '42']
     */
    public function __construct(protected array $routeParams)
    {
    }

    /**
     * Run an action with the origin check and the before and after filters
     * around it. The router calls this with the action its route names
     *
     * @param string $action Action method, e.g. indexAction
     */
    public function runAction(string $action): void
    {
        if (!$this->isSameOrigin()) {
            http_response_code(403);
            exit;
        }

        if ($this->before() !== false) {
            $this->$action();
            $this->after();
        }
    }

    /**
     * Run before each action
     *
     * @return bool|null False stops the action
     */
    protected function before(): ?bool
    {
        return null;
    }

    /**
     * Run after each action
     */
    protected function after(): void
    {
    }

    /**
     * Whether the request may run: a GET or HEAD always, anything else only
     * from a page on this site (CSRF)
     */
    protected function isSameOrigin(): bool
    {
        if (in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', ['GET', 'HEAD'], true)) {
            return true;
        }

        $source = $_SERVER['HTTP_ORIGIN'] ?? $_SERVER['HTTP_REFERER'] ?? null;

        return SameOrigin::isFromThisSite($source, $_SERVER['HTTP_HOST'] ?? null, Config::isHttpsRequest());
    }

    /**
     * A text field from the request, or '' when it's missing or isn't text
     *
     * A list (name[]=…) cast with (string) would warn, and every warning is a 500
     *
     * @param array<string, mixed> $input $_POST, $_GET or $_COOKIE
     */
    protected function stringFrom(array $input, string $name): string
    {
        $value = $input[$name] ?? '';

        return is_string($value) ? $value : '';
    }

    /**
     * Reply with JSON
     *
     * @param int $status HTTP status
     */
    protected function json(mixed $data, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json');
        echo json_encode($data);
    }

    /**
     * Show why something wasn't done as a warning on another page of this
     * site, and end the request
     *
     * @param string $message A full sentence
     * @param string $page A path on this site
     */
    protected function refuse(string $message, string $page): never
    {
        Flash::addMessage($message, Flash::WARNING);
        $this->redirect($page);
    }

    /**
     * Send the browser to another page of this site, and end the request
     *
     * @param string $url A path on this site
     */
    public function redirect(string $url): never
    {
        header('Location: ' . $url, true, 303);
        exit;
    }

    /**
     * Send someone who isn't logged in to the login page, and back here
     * after; a background request gets a 401 instead
     */
    public function requireLogin(): void
    {
        if (Auth::getUser()) {
            return;
        }

        // Background requests (chat polling, sending) can't follow a
        // redirect to the login page; tell them to sign in again instead
        if (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest') {
            $this->json(['error' => 'Please log in again.'], 401);
            exit;
        }

        Flash::addMessage('Please log in first.', Flash::INFO);

        Auth::rememberRequestedPage();

        $this->redirect('/login');
    }
}

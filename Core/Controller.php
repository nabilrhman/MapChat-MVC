<?php

namespace Core;

use App\Auth;
use App\Flash;

/**
 * Base controller
 */
#[\AllowDynamicProperties]
abstract class Controller
{
    /**
     * Parameters from the matched route
     * @var array
     */
    protected $route_params = [];

    /**
     * Class constructor
     *
     * @param array $route_params  Parameters from the route
     *
     * @return void
     */
    public function __construct($route_params)
    {
        $this->route_params = $route_params;
    }

    /**
     * Magic method called when a non-existent or inaccessible method is
     * called on an object of this class. Used to execute before and after
     * filter methods on action methods. Action methods need to be named
     * with an "Action" suffix, e.g. indexAction, showAction etc.
     *
     * @param string $name  Method name
     * @param array $args Arguments passed to the method
     *
     * @return void
     */
    public function __call($name, $args)
    {
        $method = $name . 'Action';

        if (method_exists($this, $method)) {
            if (!$this->isSameOrigin()) {
                http_response_code(403);
                exit;
            }

            if ($this->before() !== false) {
                $this->$method(...$args);
                $this->after();
            }
        } else {
            throw new \Exception("Method $method not found in controller " . get_class($this), 404);
        }
    }

    /**
     * Before filter - called before an action method.
     *
     * @return void
     */
    protected function before()
    {
    }

    /**
     * After filter - called after an action method.
     *
     * @return void
     */
    protected function after()
    {
    }

    /**
     * False for a post sent from another website (CSRF)
     *
     * @return boolean
     */
    protected function isSameOrigin()
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            return true;
        }

        $source = $_SERVER['HTTP_ORIGIN'] ?? $_SERVER['HTTP_REFERER'] ?? '';
        if ($source === '' || $source === 'null') {
            return $source === '';
        }

        return parse_url($source, PHP_URL_HOST) === parse_url('//' . ($_SERVER['HTTP_HOST'] ?? ''), PHP_URL_HOST);
    }

    /**
     * Stop unless the request is a POST
     *
     * @return void
     */
    protected function requirePost()
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            exit;
        }
    }

    /**
     * Reply with JSON
     *
     * @param mixed $data
     * @param int $status HTTP status
     *
     * @return void
     */
    protected function json($data, $status = 200)
    {
        http_response_code($status);
        header('Content-Type: application/json');
        echo json_encode($data);
    }

    /**
     * Redirect to a different page
     *
     * @param string $url  The relative URL
     *
     * @return void
     */
    public function redirect($url)
    {
        header('Location: ' . $url, true, 303);
        exit;
    }

    /**
     * Require the user to be logged in before giving access to the requested page.
     * Remember the requested page for later, then redirect to the login page.
     *
     * @return void
     */
    public function requireLogin()
    {
        if (! Auth::getUser()) {
            // Background requests (chat polling, sending) can't follow a
            // redirect to the login page; tell them to sign in again instead
            if (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest') {
                $this->json(['error' => 'Please log in again.'], 401);
                exit;
            }

            Flash::addMessage('Please log in first', Flash::INFO);

            Auth::rememberRequestedPage();

            $this->redirect('/login');
        }
    }
}

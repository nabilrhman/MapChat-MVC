<?php

namespace Core;

/**
 * Router
 */
class Router
{
    /**
     * The routing table: each route's method, URL pattern and action
     * @var array
     */
    protected $routes = [];

    /**
     * Add a GET route; HEAD requests take it too
     *
     * @param string $route  The route URL
     * @param array  $action Controller class and action method
     *
     * @return void
     */
    public function get($route, $action)
    {
        $this->add('GET', $route, $action);
    }

    /**
     * Add a POST route
     *
     * @param string $route  The route URL
     * @param array  $action Controller class and action method
     *
     * @return void
     */
    public function post($route, $action)
    {
        $this->add('POST', $route, $action);
    }

    /**
     * Add a route to the routing table
     *
     * @param string $method HTTP method
     * @param string $route  The route URL, e.g. /avatar/{id:\d+}
     * @param array  $action Controller class and action method, e.g. [Avatar::class, 'showAction']
     *
     * @return void
     */
    public function add($method, $route, $action)
    {
        // Convert the route to a regular expression: escape forward slashes
        $route = preg_replace('/\//', '\\/', $route);

        // Convert variables with custom regular expressions e.g. {id:\d+}
        $route = preg_replace('/\{([a-z]+):([^\}]+)\}/', '(?P<\1>\2)', $route);

        // Add start and end delimiters, and case insensitive flag
        $route = '/^' . $route . '$/i';

        $this->routes[] = ['method' => $method, 'pattern' => $route, 'action' => $action];
    }

    /**
     * Find the route for a request
     *
     * @param string $method HTTP method
     * @param string $path   The URL's path, without the query string
     *
     * @return array The route's action and the values of its variables
     *
     * @throws \Exception 404 when no route has this path
     * @throws MethodNotAllowed when routes have this path, but not for this method
     */
    public function match($method, $path)
    {
        $allowed = [];

        foreach ($this->routes as $route) {
            if (!preg_match($route['pattern'], $path, $matches)) {
                continue;
            }

            // HEAD runs the GET action; the server leaves the body out
            if ($route['method'] === $method || ($method === 'HEAD' && $route['method'] === 'GET')) {
                $params = array_filter($matches, 'is_string', ARRAY_FILTER_USE_KEY);

                return ['action' => $route['action'], 'params' => $params];
            }

            $allowed[] = $route['method'];
        }

        if (!$allowed) {
            throw new \Exception("No route matched $method $path", 404);
        }

        if (in_array('GET', $allowed)) {
            $allowed[] = 'HEAD';
        }

        throw new MethodNotAllowed(array_values(array_unique($allowed)));
    }

    /**
     * Dispatch the route, creating the controller object and running the
     * action method
     *
     * @param string $method HTTP method
     * @param string $url    The requested URL, e.g. /password/reset/1a2b?x=1
     *
     * @return void
     */
    public function dispatch($method, $url)
    {
        // The query string is already in $_GET; only the path picks the route
        $path = explode('?', $url, 2)[0];

        $route = $this->match($method, $path);
        [$class, $action] = $route['action'];

        $controller = new $class($route['params']);
        $controller->runAction($action);
    }
}

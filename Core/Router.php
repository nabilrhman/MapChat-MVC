<?php

namespace Core;

/**
 * The routing table: each URL pattern with the method it takes and the
 * controller action it runs. Nothing else can be reached from a URL
 */
class Router
{
    /**
     * The routes in the order they were added
     *
     * @var array<array{method: string, pattern: string, action: array{class-string, string}}>
     */
    protected array $routes = [];

    /**
     * Add a GET route; HEAD requests take it too
     *
     * @param array{class-string, string} $action Controller class and action method
     */
    public function get(string $route, array $action): void
    {
        $this->add('GET', $route, $action);
    }

    /**
     * Add a POST route
     *
     * @param array{class-string, string} $action Controller class and action method
     */
    public function post(string $route, array $action): void
    {
        $this->add('POST', $route, $action);
    }

    /**
     * Add a route to the routing table
     *
     * @param string $route The route URL, e.g. /avatar/{id:\d+}
     * @param array{class-string, string} $action Controller class and action method, e.g. [Avatar::class, 'showAction']
     */
    public function add(string $method, string $route, array $action): void
    {
        $pattern = str_replace('/', '\/', $route);
        // A variable with its own pattern, {id:\d+}, becomes a named group
        $pattern = preg_replace('/\{([a-z]+):([^\}]+)\}/', '(?P<\1>\2)', $pattern);

        $this->routes[] = ['method' => $method, 'pattern' => '/^' . $pattern . '$/i', 'action' => $action];
    }

    /**
     * Find the route for a request
     *
     * @param string $path The URL's path, without the query string
     *
     * @return array{action: array{class-string, string}, params: array<string, string>} The route's action and the values of its variables
     *
     * @throws \Exception 404 when no route has this path
     * @throws MethodNotAllowed when routes have this path, but not for this method
     */
    public function match(string $method, string $path): array
    {
        $allowed = [];

        foreach ($this->routes as $route) {
            if (!preg_match($route['pattern'], $path, $matches)) {
                continue;
            }

            // HEAD runs the GET action; the server leaves the body out
            if ($route['method'] === $method || ($method === 'HEAD' && $route['method'] === 'GET')) {
                $params = array_filter($matches, is_string(...), ARRAY_FILTER_USE_KEY);

                return ['action' => $route['action'], 'params' => $params];
            }

            $allowed[] = $route['method'];
        }

        if (!$allowed) {
            throw new \Exception("No route matched $method $path", 404);
        }

        if (in_array('GET', $allowed, true)) {
            $allowed[] = 'HEAD';
        }

        throw new MethodNotAllowed(array_values(array_unique($allowed)));
    }

    /**
     * Run the action the request's route names, on a new controller
     *
     * @param string $url The requested URL, e.g. /password/reset/1a2b?x=1
     */
    public function dispatch(string $method, string $url): void
    {
        // The query string is already in $_GET; only the path picks the route
        $path = explode('?', $url, 2)[0];

        $route = $this->match($method, $path);
        [$class, $action] = $route['action'];

        $controller = new $class($route['params']);
        $controller->runAction($action);
    }
}

<?php

class Router {
    private $routes = [];

    public function get($path, $callback) {
        $this->addRoute('GET', $path, $callback);
    }

    public function post($path, $callback) {
        $this->addRoute('POST', $path, $callback);
    }

    public function put($path, $callback) {
        $this->addRoute('PUT', $path, $callback);
    }

    public function delete($path, $callback) {
        $this->addRoute('DELETE', $path, $callback);
    }

    public function patch($path, $callback) {
        $this->addRoute('PATCH', $path, $callback);
    }

    private function addRoute($method, $path, $callback) {
        // Convert path to regex (e.g., /users/{id} -> /users/([a-zA-Z0-9_-]+))
        $pattern = preg_replace('/\{([a-zA-Z0-9_-]+)\}/', '([a-zA-Z0-9_-]+)', $path);
        $pattern = "#^" . $pattern . "$#";
        $this->routes[] = [
            'method' => $method,
            'pattern' => $pattern,
            'callback' => $callback
        ];
    }

    public function run($method, $uri) {
        // Remove query string from URI
        $uri = explode('?', $uri)[0];
        // Ensure path starts with /
        if ($uri === '') $uri = '/';

        foreach ($this->routes as $route) {
            if ($route['method'] === $method && preg_match($route['pattern'], $uri, $matches)) {
                array_shift($matches); // Remove full match
                return call_user_func_array($route['callback'], $matches);
            }
        }

        // 404 Not Found
        http_response_code(404);
        echo json_encode(['error' => 'Route not found: ' . $uri]);
        return false;
    }
}

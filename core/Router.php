<?php

declare(strict_types=1);

/**
 * Router — Maps URI segments to Controller::method.
 *
 * URL pattern: domain.com/{controller}/{method}
 *   → app/controllers/{Controller}Controller.php :: {method}()
 *
 * Route options:
 *   'auth'       => true | false       (require JWT, default false)
 *   'rate_limit' => ['max' => 5, 'window' => 3600]
 *   'method'     => 'GET'|'POST'|...   (HTTP verb)
 *
 * Example route definitions (in routes/api.php):
 *   $router->post('auth/login',          'Auth', 'login',     ['rate_limit' => ['max' => 5, 'window' => 3600]]);
 *   $router->get('user/list',            'User', 'list',      ['auth' => true, 'rate_limit' => ['max' => 15, 'window' => 3600]]);
 *   $router->post('user/upload-avatar',  'User', 'uploadAvatar', ['auth' => true]);
 */
class Router
{
    private static ?Router $instance = null;
    private array   $routes   = [];
    private Logger  $logger;
    private Request $request;

    private function __construct()
    {
        $this->logger  = Logger::getInstance();
        $this->request = Request::getInstance();
    }

    public static function getInstance(): static
    {
        if (self::$instance === null) {
            self::$instance = new static();
        }
        return self::$instance;
    }

    // ── Registration helpers ─────────────────────────────────────────────────
    public function get(string $path, string $controller, string $action, array $options = []): void
    {
        $this->addRoute('GET', $path, $controller, $action, $options);
    }

    public function post(string $path, string $controller, string $action, array $options = []): void
    {
        $this->addRoute('POST', $path, $controller, $action, $options);
    }

    public function put(string $path, string $controller, string $action, array $options = []): void
    {
        $this->addRoute('PUT', $path, $controller, $action, $options);
    }

    public function patch(string $path, string $controller, string $action, array $options = []): void
    {
        $this->addRoute('PATCH', $path, $controller, $action, $options);
    }

    public function delete(string $path, string $controller, string $action, array $options = []): void
    {
        $this->addRoute('DELETE', $path, $controller, $action, $options);
    }

    private function addRoute(string $method, string $path, string $controller, string $action, array $options): void
    {
        $key = strtoupper($method) . ':' . trim($path, '/');
        $this->routes[$key] = [
            'controller' => $controller,
            'action'     => $action,
            'auth'       => $options['auth'] ?? false,
            'rate_limit' => $options['rate_limit'] ?? null,
        ];
    }

    // ── Dispatch ─────────────────────────────────────────────────────────────
    public function dispatch(): void
    {
        $method = $this->request->method();
        $uri    = trim($this->request->uri(), '/');

        // Log every incoming request
        $this->logger->logRequest($this->request->toLogArray());

        // ── Match route ──────────────────────────────────────────────────────
        $route      = null;
        $urlParams  = [];

        foreach ($this->routes as $routeKey => $routeConfig) {
            [$routeMethod, $routePath] = explode(':', $routeKey, 2);

            if ($routeMethod !== $method) continue;

            $matched = $this->matchPath($routePath, $uri, $urlParams);
            if ($matched) {
                $route = $routeConfig;
                break;
            }
        }

        if ($route === null) {
            // Try to give a helpful 405 if the path exists under a different verb
            $pathExists = false;
            foreach (array_keys($this->routes) as $rk) {
                [, $rp] = explode(':', $rk, 2);
                $tmp = [];
                if ($this->matchPath($rp, $uri, $tmp)) {
                    $pathExists = true;
                    break;
                }
            }

            if ($pathExists) {
                Response::error('Method not allowed', 405);
            }

            Response::notFound("Route '{$uri}' not found.");
        }

        // ── Rate limiting ────────────────────────────────────────────────────
        if (!empty($route['rate_limit'])) {
            try {
                $rl = RateLimit::getInstance();
                $rl->check($uri, $route['rate_limit']['max'], $route['rate_limit']['window']);
            } catch (RuntimeException $e) {
                $code = $e->getCode() ?: 429;
                Response::send($code, [], $e->getMessage(), false);
            }
        }

        // ── JWT authentication ───────────────────────────────────────────────
        $authUser = null;
        if ($route['auth']) {
            try {
                $auth     = Auth::getInstance();
                $authUser = $auth->getAuthenticatedUser();
            } catch (RuntimeException $e) {
                $code = $e->getCode() ?: 401;
                Response::send($code, [], $e->getMessage(), false);
            }
        }

        // ── Resolve controller ───────────────────────────────────────────────
        $controllerName = $route['controller'] . 'Controller';
        $action         = $route['action'];
        $controllerFile = ROOT_PATH . '/app/controllers/' . $controllerName . '.php';

        if (!file_exists($controllerFile)) {
            $this->logger->error('Controller not found', ['file' => $controllerFile]);
            Response::error('Controller not found', 500);
        }

        require_once $controllerFile;

        if (!class_exists($controllerName)) {
            Response::error("Controller class '{$controllerName}' not found", 500);
        }

        $controller = new $controllerName($this->request, $authUser);

        if (!method_exists($controller, $action)) {
            Response::error("Action '{$action}' not found in {$controllerName}", 404);
        }

        // ── Call action ──────────────────────────────────────────────────────
        try {
            $controller->$action($urlParams);
        } catch (RuntimeException $e) {
            $code = $e->getCode() ?: 500;
            $this->logger->error('Controller action failed', [
                'controller' => $controllerName,
                'action'     => $action,
                'error'      => $e->getMessage(),
            ]);
            Response::send($code, [], $e->getMessage(), false);
        } catch (Throwable $e) {
            $this->logger->error('Unexpected error', [
                'controller' => $controllerName,
                'action'     => $action,
                'error'      => $e->getMessage(),
                'trace'      => $e->getTraceAsString(),
            ]);
            $debug = Config::isDevelopment();
            Response::error(
                $debug ? $e->getMessage() : 'Internal Server Error',
                500,
                $debug ? ['trace' => explode("\n", $e->getTraceAsString())] : []
            );
        }
    }

    // ── Path matching with named segments ────────────────────────────────────
    /**
     * Match a route pattern against the request URI.
     * Supports named segments:  user/{id}  →  ['id' => '42']
     * Supports wildcards:       user/{id}/posts/{slug}
     */
    private function matchPath(string $pattern, string $uri, array &$params): bool
    {
        $patternParts = explode('/', trim($pattern, '/'));
        $uriParts     = explode('/', trim($uri, '/'));

        if (count($patternParts) !== count($uriParts)) {
            return false;
        }

        $params = [];
        foreach ($patternParts as $i => $segment) {
            if (preg_match('/^\{(\w+)\}$/', $segment, $m)) {
                $params[$m[1]] = $uriParts[$i];
            } elseif ($segment !== $uriParts[$i]) {
                return false;
            }
        }
        return true;
    }

    private function __clone() {}
}

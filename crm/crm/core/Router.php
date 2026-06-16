<?php
namespace Core;

class Router
{
    private static $routes = [];
    private static $currentGroup = '';

    public static function get(string $path, $handler, string $permission = null): void
    {
        self::addRoute('GET', $path, $handler, $permission);
    }

    public static function post(string $path, $handler, string $permission = null): void
    {
        self::addRoute('POST', $path, $handler, $permission);
    }

    public static function group(string $prefix, callable $callback): void
    {
        $oldGroup = self::$currentGroup;
        self::$currentGroup .= $prefix;
        $callback();
        self::$currentGroup = $oldGroup;
    }

    private static function addRoute(string $method, string $path, $handler, string $permission = null): void
    {
        $fullPath = self::$currentGroup . $path;
        
        // Convert route parameters to regex: {param} -> (?P<param>[^/]+)
        $pattern = preg_replace('/\{([a-zA-Z_][a-zA-Z0-9_]*)\}/', '(?P<$1>[^/]+)', $fullPath);
        $pattern = '#^' . $pattern . '$#';
        
        self::$routes[] = [
            'method' => $method,
            'pattern' => $pattern,
            'handler' => $handler,
            'permission' => $permission,
        ];
    }

    public static function dispatch(): void
    {
        $method = $_SERVER['REQUEST_METHOD'];
        $url = $_GET['url'] ?? '';
        $url = '/' . trim($url, '/');
        
        // If URL is empty or just slash, redirect to dashboard
        if ($url === '/' || $url === '') {
            $url = '/dashboard';
        }

        foreach (self::$routes as $route) {
            if ($route['method'] !== $method) continue;

            if (preg_match($route['pattern'], $url, $matches)) {
                // Check authentication
                if (strpos($url, '/login') === false && strpos($url, '/install') === false && strpos($url, '/setup') === false) {
                    Auth::requireAuth();
                }

                // Check permission
                if ($route['permission']) {
                    Auth::requirePermission($route['permission']);
                }

                // Extract named parameters
                $params = array_filter($matches, 'is_string', ARRAY_FILTER_USE_KEY);
                
                // Call handler
                $handler = $route['handler'];
                if (is_array($handler)) {
                    [$class, $method] = $handler;
                    $controller = new $class();
                    call_user_func_array([$controller, $method], $params);
                } elseif (is_callable($handler)) {
                    call_user_func_array($handler, $params);
                }

                return;
            }
        }

        // 404 Not Found
        http_response_code(404);
        View::render('errors/404', ['title' => 'صفحه مورد نظر یافت نشد']);
    }
}
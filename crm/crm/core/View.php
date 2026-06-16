<?php
namespace Core;

class View
{
    public static function render(string $view, array $data = []): void
    {
        extract($data);
        
        $viewPath = __DIR__ . '/../views/' . $view . '.php';
        
        if (!file_exists($viewPath)) {
            throw new \Exception("View not found: {$view}");
        }
        
        // Determine if we need a layout
        $useLayout = true;
        $layoutParts = explode('/', $view);
        
        // Skip layout for login, errors, and partial views
        if (in_array($layoutParts[0], ['auth', 'errors', 'partials'])) {
            $useLayout = false;
        }
        
        if ($useLayout) {
            // Store content and render with layout
            ob_start();
            require $viewPath;
            $content = ob_get_clean();
            
            $layoutData = array_merge($data, ['content' => $content]);
            extract($layoutData);
            
            $layoutPath = __DIR__ . '/../views/layouts/main.php';
            if (file_exists($layoutPath)) {
                require $layoutPath;
            } else {
                echo $content;
            }
        } else {
            require $viewPath;
        }
    }

    public static function renderPartial(string $view, array $data = []): string
    {
        extract($data);
        
        $viewPath = __DIR__ . '/../views/' . $view . '.php';
        
        if (!file_exists($viewPath)) {
            return "<!-- View not found: {$view} -->";
        }
        
        ob_start();
        require $viewPath;
        return ob_get_clean();
    }

    public static function json($data, int $statusCode = 200): void
    {
        http_response_code($statusCode);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_UNESCAPED_UNICODE);
        exit;
    }

    public static function redirect(string $url): void
    {
        $config = $GLOBALS['app_config'];
        header('Location: ' . $config['url'] . $url);
        exit;
    }

    public static function back(): void
    {
        $referer = $_SERVER['HTTP_REFERER'] ?? '/dashboard';
        header('Location: ' . $referer);
        exit;
    }
}
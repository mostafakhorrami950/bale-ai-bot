<?php
namespace Core;

class Auth
{
    private static $user = null;

    public static function attempt(string $username, string $password): bool
    {
        $db = Database::getInstance();
        $user = $db->fetch(
            "SELECT u.*, r.name as role_name, r.slug as role_slug 
             FROM users u 
             JOIN roles r ON u.role_id = r.id 
             WHERE u.username = :username AND u.is_active = 1",
            [':username' => $username]
        );

        if ($user && password_verify($password, $user->password)) {
            Session::set('user_id', $user->id);
            Session::set('user_name', $user->full_name);
            Session::set('role_slug', $user->role_slug);
            
            // Update last login
            $db->update('users', ['last_login' => date('Y-m-d H:i:s')], 'id = :id', [':id' => $user->id]);
            
            // Log activity
            ActivityLog::log('login', 'user', $user->id, 'ورود به سیستم');
            
            return true;
        }

        return false;
    }

    public static function user(): ?object
    {
        if (self::$user === null) {
            $userId = Session::get('user_id');
            if ($userId) {
                $db = Database::getInstance();
                self::$user = $db->fetch(
                    "SELECT u.*, r.name as role_name, r.slug as role_slug 
                     FROM users u 
                     JOIN roles r ON u.role_id = r.id 
                     WHERE u.id = :id",
                    [':id' => $userId]
                );
            }
        }
        return self::$user;
    }

    public static function id(): ?int
    {
        return Session::get('user_id');
    }

    public static function check(): bool
    {
        return Session::has('user_id');
    }

    public static function logout(): void
    {
        ActivityLog::log('logout', 'user', self::id(), 'خروج از سیستم');
        Session::destroy();
    }

    public static function hasPermission(string $permission): bool
    {
        $user = self::user();
        if (!$user) return false;

        // Super admin has all permissions
        if ($user->role_slug === 'super_admin') return true;

        $db = Database::getInstance();
        $result = $db->fetch(
            "SELECT COUNT(*) as count FROM role_permissions 
             WHERE role_id = :role_id AND permission = :permission",
            [':role_id' => $user->role_id, ':permission' => $permission]
        );

        return $result && $result->count > 0;
    }

    public static function requireAuth(): void
    {
        if (!self::check()) {
            Session::setFlash('danger', 'لطفا ابتدا وارد سیستم شوید.');
            header('Location: ' . $GLOBALS['app_config']['url'] . '/login');
            exit;
        }
    }

    public static function requirePermission(string $permission): void
    {
        self::requireAuth();
        if (!self::hasPermission($permission)) {
            Session::setFlash('danger', 'شما دسترسی به این بخش را ندارید.');
            header('Location: ' . $GLOBALS['app_config']['url'] . '/dashboard');
            exit;
        }
    }
}
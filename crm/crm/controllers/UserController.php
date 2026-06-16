<?php
namespace Controllers;

use Core\Auth;
use Core\Database;
use Core\Session;
use Core\View;
use Core\ActivityLog;

class UserController
{
    public function index(): void
    {
        $db = Database::getInstance();
        $users = $db->fetchAll(
            "SELECT u.*, r.name as role_name, r.slug as role_slug
             FROM users u 
             JOIN roles r ON u.role_id = r.id 
             ORDER BY u.created_at DESC"
        );
        View::render('users/index', ['title' => 'مدیریت کاربران', 'users' => $users]);
    }

    public function create(): void
    {
        $db = Database::getInstance();
        $roles = $db->fetchAll("SELECT * FROM roles WHERE is_active = 1 ORDER BY name");
        View::render('users/create', ['title' => 'ایجاد کاربر جدید', 'roles' => $roles]);
    }

    public function store(): void
    {
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';
        $fullName = trim($_POST['full_name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $roleId = (int)($_POST['role_id'] ?? 0);

        if (empty($username) || empty($password) || empty($fullName) || empty($roleId)) {
            Session::setFlash('danger', 'لطفا تمام فیلدهای ضروری را پر کنید.');
            View::redirect('/users/create');
        }

        $db = Database::getInstance();
        
        // Check duplicate username
        $exists = $db->fetch("SELECT id FROM users WHERE username = :username", [':username' => $username]);
        if ($exists) {
            Session::setFlash('danger', 'این نام کاربری قبلاً ثبت شده است.');
            View::redirect('/users/create');
        }

        $hashedPassword = password_hash($password, PASSWORD_BCRYPT);
        $userId = $db->insert('users', [
            'username' => $username,
            'password' => $hashedPassword,
            'full_name' => $fullName,
            'email' => $email,
            'phone' => $phone,
            'role_id' => $roleId,
            'is_active' => 1,
        ]);

        ActivityLog::log('create_user', 'user', $userId, "کاربر {$fullName} ایجاد شد");
        Session::setFlash('success', 'کاربر با موفقیت ایجاد شد.');
        View::redirect('/users');
    }

    public function edit(array $params): void
    {
        $db = Database::getInstance();
        $user = $db->fetch("SELECT * FROM users WHERE id = :id", [':id' => $params['id']]);
        if (!$user) {
            Session::setFlash('danger', 'کاربر مورد نظر یافت نشد.');
            View::redirect('/users');
        }
        $roles = $db->fetchAll("SELECT * FROM roles WHERE is_active = 1 ORDER BY name");
        View::render('users/edit', ['title' => 'ویرایش کاربر', 'user' => $user, 'roles' => $roles]);
    }

    public function update(array $params): void
    {
        $fullName = trim($_POST['full_name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $roleId = (int)($_POST['role_id'] ?? 0);
        $isActive = isset($_POST['is_active']) ? 1 : 0;
        $password = $_POST['password'] ?? '';

        $db = Database::getInstance();
        
        $data = [
            'full_name' => $fullName,
            'email' => $email,
            'phone' => $phone,
            'role_id' => $roleId,
            'is_active' => $isActive,
        ];

        if (!empty($password)) {
            $data['password'] = password_hash($password, PASSWORD_BCRYPT);
        }

        $db->update('users', $data, 'id = :id', [':id' => $params['id']]);
        
        ActivityLog::log('update_user', 'user', $params['id'], "کاربر {$fullName} ویرایش شد");
        Session::setFlash('success', 'کاربر با موفقیت ویرایش شد.');
        View::redirect('/users');
    }

    public function delete(array $params): void
    {
        $db = Database::getInstance();
        $user = $db->fetch("SELECT full_name FROM users WHERE id = :id", [':id' => $params['id']]);
        
        if ($user) {
            $db->delete('users', 'id = :id', [':id' => $params['id']]);
            ActivityLog::log('delete_user', 'user', $params['id'], "کاربر {$user->full_name} حذف شد");
            Session::setFlash('success', 'کاربر با موفقیت حذف شد.');
        }
        View::redirect('/users');
    }
}
<?php
namespace Controllers;

use Core\Auth;
use Core\Database;
use Core\Session;
use Core\View;
use Core\ActivityLog;

class RoleController
{
    public function index(): void
    {
        $db = Database::getInstance();
        $roles = $db->fetchAll(
            "SELECT r.*, (SELECT COUNT(*) FROM users WHERE role_id = r.id) as user_count
             FROM roles r ORDER BY r.created_at DESC"
        );
        View::render('roles/index', ['title' => 'مدیریت نقش‌ها', 'roles' => $roles]);
    }

    public function create(): void
    {
        $db = Database::getInstance();
        $permissions = $db->fetchAll("SELECT * FROM permissions ORDER BY group_name, name");
        $permissionsByGroup = [];
        foreach ($permissions as $p) {
            $permissionsByGroup[$p->group_name][] = $p;
        }
        View::render('roles/create', ['title' => 'ایجاد نقش جدید', 'permissionsByGroup' => $permissionsByGroup]);
    }

    public function store(): void
    {
        $name = trim($_POST['name'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $permissions = $_POST['permissions'] ?? [];

        if (empty($name)) {
            Session::setFlash('danger', 'لطفا نام نقش را وارد کنید.');
            View::redirect('/roles/create');
        }

        $db = Database::getInstance();
        $slug = str_replace(' ', '_', $name);
        
        $roleId = $db->insert('roles', [
            'name' => $name,
            'slug' => $slug,
            'description' => $description,
            'is_system' => 0,
        ]);

        // Insert permissions
        foreach ($permissions as $perm) {
            $db->insert('role_permissions', [
                'role_id' => $roleId,
                'permission' => $perm,
            ]);
        }

        ActivityLog::log('create_role', 'role', $roleId, "نقش {$name} ایجاد شد");
        Session::setFlash('success', 'نقش با موفقیت ایجاد شد.');
        View::redirect('/roles');
    }

    public function edit(array $params): void
    {
        $db = Database::getInstance();
        $role = $db->fetch("SELECT * FROM roles WHERE id = :id", [':id' => $params['id']]);
        if (!$role) {
            Session::setFlash('danger', 'نقش مورد نظر یافت نشد.');
            View::redirect('/roles');
        }

        $permissions = $db->fetchAll("SELECT * FROM permissions ORDER BY group_name, name");
        $permissionsByGroup = [];
        foreach ($permissions as $p) {
            $permissionsByGroup[$p->group_name][] = $p;
        }

        $rolePerms = $db->fetchAll("SELECT permission FROM role_permissions WHERE role_id = :id", [':id' => $params['id']]);
        $rolePermsArr = array_map(function($p) { return $p->permission; }, $rolePerms);

        View::render('roles/edit', [
            'title' => 'ویرایش نقش',
            'role' => $role,
            'permissionsByGroup' => $permissionsByGroup,
            'rolePerms' => $rolePermsArr,
        ]);
    }

    public function update(array $params): void
    {
        $name = trim($_POST['name'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $permissions = $_POST['permissions'] ?? [];

        $db = Database::getInstance();
        $db->update('roles', [
            'name' => $name,
            'description' => $description,
        ], 'id = :id', [':id' => $params['id']]);

        // Update permissions
        $db->delete('role_permissions', 'role_id = :id', [':id' => $params['id']]);
        foreach ($permissions as $perm) {
            $db->insert('role_permissions', [
                'role_id' => $params['id'],
                'permission' => $perm,
            ]);
        }

        ActivityLog::log('update_role', 'role', $params['id'], "نقش {$name} ویرایش شد");
        Session::setFlash('success', 'نقش با موفقیت ویرایش شد.');
        View::redirect('/roles');
    }

    public function delete(array $params): void
    {
        $db = Database::getInstance();
        $role = $db->fetch("SELECT * FROM roles WHERE id = :id", [':id' => $params['id']]);
        
        if ($role && !$role->is_system) {
            $db->delete('roles', 'id = :id', [':id' => $params['id']]);
            ActivityLog::log('delete_role', 'role', $params['id'], "نقش {$role->name} حذف شد");
            Session::setFlash('success', 'نقش با موفقیت حذف شد.');
        } else {
            Session::setFlash('danger', 'این نقش قابل حذف نیست.');
        }
        View::redirect('/roles');
    }
}
<?php
namespace Controllers;

use Core\Auth;
use Core\Database;
use Core\Session;
use Core\View;
use Core\ActivityLog;

class ContactController
{
    public function index(): void
    {
        $db = Database::getInstance();
        $search = $_GET['search'] ?? '';
        
        $where = "WHERE 1=1";
        $params = [];
        
        if ($search) {
            $where .= " AND (c.full_name LIKE :search OR c.phone LIKE :search2 OR c.email LIKE :search3)";
            $params[':search'] = "%{$search}%";
            $params[':search2'] = "%{$search}%";
            $params[':search3'] = "%{$search}%";
        }

        $contacts = $db->fetchAll(
            "SELECT c.*, u.full_name as created_by_name,
                    (SELECT COUNT(*) FROM deals WHERE contact_id = c.id) as deals_count,
                    (SELECT SUM(amount) FROM deals WHERE contact_id = c.id AND is_won = 1) as total_purchases
             FROM contacts c 
             LEFT JOIN users u ON c.created_by = u.id 
             {$where}
             ORDER BY c.created_at DESC",
            $params
        );

        View::render('contacts/index', ['title' => 'مدیریت مخاطبان', 'contacts' => $contacts, 'search' => $search]);
    }

    public function create(): void
    {
        View::render('contacts/create', ['title' => 'ایجاد مخاطب جدید']);
    }

    public function store(): void
    {
        $fullName = trim($_POST['full_name'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $nationalCode = trim($_POST['national_code'] ?? '');
        $passportNumber = trim($_POST['passport_number'] ?? '');
        $address = trim($_POST['address'] ?? '');
        $company = trim($_POST['company'] ?? '');
        $notes = trim($_POST['notes'] ?? '');
        $source = trim($_POST['source'] ?? '');
        $tags = trim($_POST['tags'] ?? '');

        if (empty($fullName)) {
            Session::setFlash('danger', 'لطفا نام مخاطب را وارد کنید.');
            View::redirect('/contacts/create');
        }

        $db = Database::getInstance();
        $contactId = $db->insert('contacts', [
            'full_name' => $fullName,
            'phone' => $phone,
            'email' => $email,
            'national_code' => $nationalCode,
            'passport_number' => $passportNumber,
            'address' => $address,
            'company' => $company,
            'notes' => $notes,
            'source' => $source,
            'tags' => $tags,
            'created_by' => Auth::id(),
        ]);

        ActivityLog::log('create_contact', 'contact', $contactId, "مخاطب {$fullName} ایجاد شد");
        Session::setFlash('success', 'مخاطب با موفقیت ایجاد شد.');
        View::redirect('/contacts');
    }

    public function edit(array $params): void
    {
        $db = Database::getInstance();
        $contact = $db->fetch("SELECT * FROM contacts WHERE id = :id", [':id' => $params['id']]);
        if (!$contact) {
            Session::setFlash('danger', 'مخاطب مورد نظر یافت نشد.');
            View::redirect('/contacts');
        }
        View::render('contacts/edit', ['title' => 'ویرایش مخاطب', 'contact' => $contact]);
    }

    public function update(array $params): void
    {
        $fullName = trim($_POST['full_name'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $nationalCode = trim($_POST['national_code'] ?? '');
        $passportNumber = trim($_POST['passport_number'] ?? '');
        $address = trim($_POST['address'] ?? '');
        $company = trim($_POST['company'] ?? '');
        $notes = trim($_POST['notes'] ?? '');
        $source = trim($_POST['source'] ?? '');
        $tags = trim($_POST['tags'] ?? '');

        $db = Database::getInstance();
        $db->update('contacts', [
            'full_name' => $fullName,
            'phone' => $phone,
            'email' => $email,
            'national_code' => $nationalCode,
            'passport_number' => $passportNumber,
            'address' => $address,
            'company' => $company,
            'notes' => $notes,
            'source' => $source,
            'tags' => $tags,
        ], 'id = :id', [':id' => $params['id']]);

        ActivityLog::log('update_contact', 'contact', $params['id'], "مخاطب {$fullName} ویرایش شد");
        Session::setFlash('success', 'مخاطب با موفقیت ویرایش شد.');
        View::redirect('/contacts');
    }

    public function delete(array $params): void
    {
        $db = Database::getInstance();
        $contact = $db->fetch("SELECT full_name FROM contacts WHERE id = :id", [':id' => $params['id']]);
        if ($contact) {
            $db->delete('contacts', 'id = :id', [':id' => $params['id']]);
            ActivityLog::log('delete_contact', 'contact', $params['id'], "مخاطب {$contact->full_name} حذف شد");
            Session::setFlash('success', 'مخاطب با موفقیت حذف شد.');
        }
        View::redirect('/contacts');
    }
}
<?php
namespace Core;

use PDO;

class Migration
{
    private $db;
    private $config;

    public function __construct()
    {
        $this->config = require __DIR__ . '/../config/app.php';
        $db = $this->config['db'];
        
        // First connect without database to create it if needed
        try {
            $pdo = new PDO(
                "mysql:host={$db['host']};port={$db['port']};charset=utf8mb4",
                $db['user'],
                $db['pass'],
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
            );
            
            $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$db['name']}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
            $pdo->exec("USE `{$db['name']}`");
            
            $this->db = $pdo;
        } catch (\PDOException $e) {
            die("Migration failed: " . $e->getMessage());
        }
    }

    public function run(): void
    {
        echo "Running migrations...\n";
        
        $this->createMigrationsTable();
        $executed = $this->getExecutedMigrations();
        
        $migrations = $this->getMigrationFiles();
        
        foreach ($migrations as $migration) {
            if (!in_array($migration, $executed)) {
                $this->executeMigration($migration);
            }
        }
        
        echo "All migrations completed successfully!\n";
        
        // Seed default data
        $this->seed();
    }
    
    private function createMigrationsTable(): void
    {
        $this->db->exec("CREATE TABLE IF NOT EXISTS migrations (
            id INT AUTO_INCREMENT PRIMARY KEY,
            migration VARCHAR(255) NOT NULL,
            executed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }
    
    private function getExecutedMigrations(): array
    {
        $stmt = $this->db->query("SELECT migration FROM migrations ORDER BY id");
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }
    
    private function getMigrationFiles(): array
    {
        $files = glob(__DIR__ . '/../database/migrations/*.sql');
        sort($files);
        return array_map(function($f) {
            return basename($f);
        }, $files);
    }
    
    private function executeMigration(string $migration): void
    {
        echo "  Running: {$migration}\n";
        $sql = file_get_contents(__DIR__ . '/../database/migrations/' . $migration);
        
        try {
            $this->db->exec($sql);
            $stmt = $this->db->prepare("INSERT INTO migrations (migration) VALUES (:m)");
            $stmt->execute([':m' => $migration]);
            echo "  ✓ {$migration} completed\n";
        } catch (\PDOException $e) {
            echo "  ✗ {$migration} failed: " . $e->getMessage() . "\n";
            throw $e;
        }
    }
    
    private function seed(): void
    {
        // Check if super admin exists
        $stmt = $this->db->query("SELECT COUNT(*) as count FROM users");
        $result = $stmt->fetch(PDO::FETCH_OBJ);
        
        if ($result->count == 0) {
            echo "  Seeding default data...\n";
            
            // Create default admin role
            $this->db->exec("INSERT INTO roles (name, slug, description, is_system) VALUES 
                ('مدیر اصلی', 'super_admin', 'دسترسی کامل به تمام بخش‌ها', 1),
                ('اپراتور', 'operator', 'مدیریت معاملات و مشتریان خود', 1),
                ('مدیر فروش', 'sales_manager', 'مدیریت تیم فروش و مشاهده گزارشات', 1)
            ");
            
            // Create super admin user (username: admin, password: admin123)
            $password = password_hash('admin123', PASSWORD_BCRYPT);
            $stmt = $this->db->prepare("INSERT INTO users (username, password, full_name, role_id, is_active) VALUES 
                ('admin', :pass, 'مدیر اصلی', 1, 1)
            ");
            $stmt->execute([':pass' => $password]);
            
            // Create default pipeline for travel agency
            $this->db->exec("INSERT INTO pipelines (name, description, is_default) VALUES 
                ('فروش تور', 'پایپ لاین فروش تورهای مسافرتی', 1)
            ");
            
            // Create default stages
            $this->db->exec("INSERT INTO stages (pipeline_id, name, color, order_index) VALUES 
                (1, 'مشتری جدید', '#6B7280', 1),
                (1, 'در حال مذاکره', '#F59E0B', 2),
                (1, 'پیش‌فاکتور', '#3B82F6', 3),
                (1, 'در انتظار پرداخت', '#8B5CF6', 4),
                (1, 'پرداخت شده', '#10B981', 5),
                (1, 'لغو شده', '#EF4444', 6)
            ");
            
            // Set permissions for roles
            // Super admin gets all permissions
            $this->db->exec("INSERT INTO role_permissions (role_id, permission) 
                SELECT 1, permission FROM permissions");
            
            // Operator permissions
            $this->db->exec("INSERT INTO role_permissions (role_id, permission) VALUES 
                (2, 'deals.view'), (2, 'deals.create'), (2, 'deals.edit'), (2, 'deals.delete'),
                (2, 'contacts.view'), (2, 'contacts.create'), (2, 'contacts.edit'),
                (2, 'pipelines.view')
            ");
            
            // Sales manager permissions
            $this->db->exec("INSERT INTO role_permissions (role_id, permission) VALUES 
                (3, 'deals.view'), (3, 'deals.create'), (3, 'deals.edit'), (3, 'deals.delete'),
                (3, 'contacts.view'), (3, 'contacts.create'), (3, 'contacts.edit'), (3, 'contacts.delete'),
                (3, 'pipelines.view'), (3, 'pipelines.create'), (3, 'pipelines.edit'),
                (3, 'reports.view'), (3, 'reports.export')
            ");
            
            echo "  ✓ Default data seeded (admin/admin123)\n";
        }
    }
}
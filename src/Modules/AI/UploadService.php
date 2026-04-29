<?php

namespace Modules\AI;

use Core\Config;
use Database\Database;
use Database\Logger;

class UploadService
{
    private string $uploadDir;
    private string $publicBaseUrl;

    public function __construct()
    {
        $this->uploadDir = BASE_PATH . '/public/uploads/ai/';
        if (!is_dir($this->uploadDir)) {
            @mkdir($this->uploadDir, 0755, true);
        }
        // Ensure .htaccess for direct access
        $htaccess = $this->uploadDir . '.htaccess';
        if (!file_exists($htaccess)) {
            file_put_contents($htaccess, "Allow From All\n");
        }
        // PUBLIC_BASE_URL = "https://mobixai.ir", uploads are at public/uploads/ai/
        $this->publicBaseUrl = rtrim(Config::get('PUBLIC_BASE_URL', 'https://mobixai.ir'), '/');
    }

    /**
     * Save image binary data to local storage and return its public URL.
     *
     * @param int    $userId  Internal user ID
     * @param string $binary  Raw image binary data
     * @param string $ext     File extension (jpg, png, etc)
     * @param string $source  Source context (img2img, text2img, etc)
     * @return string|null    Public URL on success, null on failure
     */
    public function saveImage(int $userId, string $binary, string $ext = 'jpg', string $source = 'img2img'): ?string
    {
        if (empty($binary)) {
            Logger::error('UploadService::saveImage empty binary', ['user_id' => $userId]);
            return null;
        }

        $filename = $userId . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
        $localPath = $this->uploadDir . $filename;

        $written = file_put_contents($localPath, $binary);
        if ($written === false || $written === 0) {
            Logger::error('UploadService::saveImage write failed', [
                'user_id' => $userId,
                'path'    => $localPath,
            ]);
            return null;
        }

        $publicUrl = $this->publicBaseUrl . '/public/uploads/ai/' . $filename;
        $fileSize = strlen($binary);
        $mimeType = $this->getMimeFromExt($ext);

        // Store in database for tracking/cleanup
        try {
            $db = Database::getInstance();
            $db->query(
                "INSERT INTO uploaded_files (user_id, original_filename, local_path, public_url, file_size, mime_type, source) 
                 VALUES (?, ?, ?, ?, ?, ?, ?)",
                [$userId, $filename, $localPath, $publicUrl, $fileSize, $mimeType, $source]
            );
        } catch (\Throwable $e) {
            Logger::error('UploadService::saveImage DB insert failed', [
                'user_id' => $userId,
                'error'   => $e->getMessage(),
            ]);
            // Non-critical: file is saved, DB is just for cleanup tracking
        }

        Logger::info('UploadService::saveImage success', [
            'user_id'    => $userId,
            'public_url' => $publicUrl,
            'size'       => $fileSize,
        ]);

        return $publicUrl;
    }

    /**
     * Delete old uploaded files (older than $hours).
     * Returns count of deleted files.
     */
    public function cleanOldFiles(int $hours = 24): int
    {
        $deleted = 0;
        try {
            $db = Database::getInstance();
            $cutoff = date('Y-m-d H:i:s', time() - ($hours * 3600));
            $stmt = $db->query(
                "SELECT id, local_path FROM uploaded_files WHERE created_at < ?",
                [$cutoff]
            );
            $files = $stmt->fetchAll();

            foreach ($files as $file) {
                $localPath = $file['local_path'] ?? '';
                if ($localPath && file_exists($localPath)) {
                    @unlink($localPath);
                    $deleted++;
                }
                // Remove from DB
                $db->query("DELETE FROM uploaded_files WHERE id = ?", [(int)$file['id']]);
            }

            Logger::info('UploadService::cleanOldFiles', [
                'deleted' => $deleted,
                'hours'   => $hours,
            ]);
        } catch (\Throwable $e) {
            Logger::error('UploadService::cleanOldFiles failed', ['error' => $e->getMessage()]);
        }
        return $deleted;
    }

    /**
     * Get total count and size of uploaded files.
     */
    public function getStats(): array
    {
        try {
            $db = Database::getInstance();
            $stmt = $db->query("SELECT COUNT(*) as count, COALESCE(SUM(file_size), 0) as total_size FROM uploaded_files");
            $row = $stmt->fetch();
            return [
                'count'      => (int)($row['count'] ?? 0),
                'total_size' => (int)($row['total_size'] ?? 0),
            ];
        } catch (\Throwable $e) {
            return ['count' => 0, 'total_size' => 0];
        }
    }

    private function getMimeFromExt(string $ext): string
    {
        $map = [
            'jpg'  => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'png'  => 'image/png',
            'gif'  => 'image/gif',
            'webp' => 'image/webp',
        ];
        return $map[strtolower($ext)] ?? 'image/jpeg';
    }
}
<?php
/**
 * AJAX: Generate image via AI
 * Mirrors bot's AIService::generate() logic
 */
require_once __DIR__ . '/../init.php';
requireAuth();

use Modules\AI\AIService;
use Modules\Bot\CreditService;

$input = json_decode(file_get_contents('php://input'), true);
$prompt = trim($input['prompt'] ?? '');
$modelName = trim($input['model'] ?? '');

if (!$prompt) jsonResponse(['error' => 'متن درخواست الزامی است']);

try {
    $db = Database::getInstance();
    $webUser = getWebUser();
    $botUserId = getBotUserId($webUser['id']);
    if (!$botUserId) jsonResponse(['error' => 'حساب کاربری یافت نشد']);

    $modelData = $db->query("SELECT * FROM ai_image_models WHERE name = ? AND is_active = 1", [$modelName])->fetch();
    if (!$modelData) jsonResponse(['error' => 'مدل یافت نشد']);

    $cost = (int)($modelData['cost_per_image'] ?? 2);
    if (!CreditService::hasEnoughCredit($botUserId, $cost)) {
        jsonResponse(['error' => 'اعتبار کافی نیست']);
    }

    $ai = new AIService();
    $result = $ai->generate([
        'model' => $modelName,
        'prompt' => $prompt,
        'provider' => $modelData['provider'] ?? 'openrouter',
        'model_data' => $modelData,
    ]);

    if (!empty($result['error'])) {
        jsonResponse(['error' => $result['error']]);
    }

    $images = $result['images'] ?? [];
    if (empty($images)) {
        jsonResponse(['error' => 'تصویری تولید نشد']);
    }

    // Save image locally
    $imageUrl = $images[0];
    $localPath = '';
    $publicUrl = $imageUrl;

    // Try to download and save locally
    $imageData = @file_get_contents($imageUrl);
    if ($imageData) {
        $ext = 'png';
        $filename = 'web_' . $botUserId . '_' . time() . '.' . $ext;
        $uploadDir = __DIR__ . '/../../../uploads/ai_generated/';
        if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
        file_put_contents($uploadDir . $filename, $imageData);
        $localPath = $uploadDir . $filename;
        $publicUrl = '/uploads/ai_generated/' . $filename;
    }

    // Deduct credits
    $refId = 'web_img_' . $modelName . '_' . time();
    CreditService::deduct($botUserId, $cost, $refId);

    // Log request
    $db->query(
        "INSERT INTO ai_requests (user_id, model_id, model_name, prompt, image_type, status, reference_id) VALUES (?, ?, ?, ?, 'text2img', 'success', ?)",
        [$botUserId, $modelData['id'], $modelName, $prompt, $refId]
    );

    jsonResponse(['url' => $publicUrl]);

} catch (\Throwable $e) {
    Logger::error('web generate_image', ['error' => $e->getMessage()]);
    jsonResponse(['error' => 'خطا در تولید تصویر'], 500);
}
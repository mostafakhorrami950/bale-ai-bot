<?php

namespace Modules\Bot\Handlers;

use Modules\AI\AIService;
use Modules\Bot\CreditService;
use Modules\Bot\Models\User;
use Database\Database;
use Database\Logger;

class Img2ImgHandler extends BaseHandler
{
    public function handle($update): void
    {
        try {
            $chatId = $update->getChatId();
            $userId = $update->getUserId();
            $text = $update->getText();
            $isCallback = $update->isCallback();

            if ($isCallback) {
                $this->askForPhoto($chatId, $userId);
                return;
            }

            if ($text === '🖼 ویرایش عکس') {
                $this->askForPhoto($chatId, $userId);
                return;
            }

            $state = $this->getUserState($userId);

            if ($state === 'awaiting_edit_photo') {
                if ($update->hasPhoto()) {
                    $fileId = $update->getPhotoFileId();
                    $this->storePhotoAndAskPrompt($chatId, $userId, $fileId);
                } else {
                    $this->baleClient->sendMessage($chatId, "\xF0\x9F\x93\xB8 \xDA\xA9\xD9\x87 \xDB\x8C\xDA\xA9 \xD8\xB9\xDA\xA9\xD8\xB3 \xD8\xA7\xD8\xB1\xD8\xB3\xD8\xA7\xD9\x84 \xDA\xA9\xD9\x86\xDB\x8C\xD8\xAF.");
                }
                return;
            }

            if ($state === 'awaiting_edit_prompt') {
                $photoData = $this->getStoredPhotoData($userId);
                if ($photoData) {
                    $this->processEdit($chatId, $userId, $text, $photoData);
                } else {
                    $this->baleClient->sendMessage($chatId, "\xE2\x9A\xA0\xEF\xB8\x8F \xD8\xB9\xDA\xA9\xD8\xB3 \xD8\xB0\xD8\xAE\xDB\x8C\xD8\xB1\xD9\x87 \xD8\xB4\xD8\xAF\xD9\x87 \xDB\x8C\xD8\xA7\xD9\x81\xD8\xAA \xD9\x86\xD8\xB4\xD8\xAF. \xD9\x84\xD8\xB7\xD9\x81\xD8\xA7\xD9\x8B \xD8\xAF\xD9\x88\xD8\xA8\xD8\xA7\xD8\xB1\xD9\x87 \xD8\xA7\xD8\xB2 \xD8\xA7\xD9\x88\xD9\x84 \xD8\xB4\xD8\xB1\xD9\x88\xD8\xB9 \xDA\xA9\xD9\x86\xDB\x8C\xD8\xAF.");
                    $this->clearUserStateById($userId);
                }
                return;
            }

            $this->baleClient->sendMessage($chatId, "\xF0\x9F\xA4\x96 \xDA\xA9\xD9\x87 \xD8\xA7\xD8\xB2 \xD9\x85\xD9\x86\xD9\x88\xDB\x8C \xD8\xB2\xDB\x8C\xD8\xB1 \xDA\xAF\xD8\xB2\xDB\x8C\xD9\x86\xD9\x87\xE2\x80\x8C\xD8\xA7\xDB\x8C \xD8\xB1\xD8\xA7 \xD8\xA7\xD9\x86\xD8\xAA\xD8\xAE\xD8\xA7\xD8\xA8 \xDA\xA9\xD9\x86\xDB\x8C\xD8\xAF:", $this->getPersistentKeyboard());
        } catch (\Throwable $e) {
            Logger::error('Img2ImgHandler exception', [
                'user_id' => $update->getUserId(),
                'error'   => $e->getMessage(),
                'trace'   => $e->getTraceAsString(),
            ]);
            $this->baleClient->sendMessage($update->getChatId(), "\xE2\x9A\xA0\xEF\xB8\x8F \xD9\x85\xD8\xAA\xD8\xA3\xD8\xB3\xD9\x81\xD8\xA7\xD9\x86\xD9\x87 \xD9\x85\xD8\xB4\xDA\xA9\xD9\x84\xDB\x8C \xD9\xBE\xDB\x8C\xD8\xB4 \xD8\xA2\xD9\x85\xD8\xAF. \xDA\xA9\xD9\x86\xDB\x8C\xD8\xAF \xD8\xAF\xD9\x88\xD8\xA8\xD8\xA7\xD8\xB1\xD9\x87 \xD8\xAA\xD9\x84\xD8\xA7\xD8\xB4 \xDA\xA9\xD9\x86\xDB\x8C\xD8\xAF.");
        }
    }

    private function resolveUserId(int $baleUserId): ?int
    {
        try {
            $db = Database::getInstance();
            $stmt = $db->query("SELECT id FROM users WHERE bale_user_id = ?", [$baleUserId]);
            $row = $stmt->fetch();
            return $row ? (int) $row['id'] : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function askForPhoto(int $chatId, int $userId): void
    {
        $internalId = $this->resolveUserId($userId);
        if ($internalId) {
            Database::getInstance()->query(
                "INSERT INTO bot_state (user_id, state, updated_at) VALUES (?, 'awaiting_edit_photo', NOW())
                 ON DUPLICATE KEY UPDATE state='awaiting_edit_photo', updated_at=NOW()",
                [$internalId]
            );
        }
        $this->baleClient->sendMessage($chatId, "\xF0\x9F\x96\xBC \xDA\xA9\xD9\x87 \xDB\x8C\xDA\xA9 \xDA\xA9\xD9\x87 \xD9\x85\xDB\x8C\xD8\xAE\xD9\x88\xD8\xA7\xD9\x87\xDB\x8C\xD8\xAF \xD9\x88\xDB\x8C\xD8\xB1\xD8\xA7\xDB\x8C\xD8\xB4 \xDA\xA9\xD9\x86\xDB\x8C\xD8\xAF \xD8\xB1\xD8\xA7 \xD8\xA7\xD8\xB1\xD8\xB3\xD8\xA7\xD9\x84 \xD9\x86\xD9\x85\xD8\xA7\xDB\x8C\xDB\x8C\xD8\xAF:");
    }

    private function storePhotoAndAskPrompt(int $chatId, int $userId, string $fileId): void
    {
        try {
            $photoBase64 = $this->downloadPhotoAsBase64($fileId);
            if (!$photoBase64) {
                $this->baleClient->sendMessage($chatId, "\xE2\x9A\xA0\xEF\xB8\x8F \xD8\xAF\xD8\xB1\xDB\x8C\xD8\xA7\xD9\x81\xD8\xAA \xD8\xB9\xDA\xA9\xD8\xB3 \xD8\xA7\xD8\xB2 \xD8\xB3\xD8\xB1\xD9\x88\xD8\xB1 \xD8\xA8\xD8\xA7 \xD9\x85\xD8\xB4\xDA\xA9\xD9\x84 \xD9\x85\xD9\x88\xD8\xA7\xD8\xAC\xD9\x87 \xD8\xB4\xD8\xAF. \xD9\x84\xD8\xB7\xD9\x81\xD8\xA7\xD9\x8B \xD8\xAF\xD9\x88\xD8\xA8\xD8\xA7\xD8\xB1\xD9\x87 \xD8\xAA\xD9\x84\xD8\xA7\xD8\xB4 \xDA\xA9\xD9\x86\xDB\x8C\xD8\xAF.");
                return;
            }

            $internalId = $this->resolveUserId($userId);
            if ($internalId) {
                Database::getInstance()->query(
                    "INSERT INTO bot_state (user_id, state, photo_base64, extra_data, updated_at)
                     VALUES (?, 'awaiting_edit_prompt', ?, ?, NOW())
                     ON DUPLICATE KEY UPDATE state='awaiting_edit_prompt', photo_base64=?, extra_data=?, updated_at=NOW()",
                    [$internalId, $photoBase64, '{}', $photoBase64, '{}']
                );
            }

            $this->baleClient->sendMessage($chatId, "\xE2\x9C\x8F\xEF\xB8\x8F \xD8\xB9\xDA\xA9\xD8\xB3 \xD8\xAF\xD8\xB1\xDB\x8C\xD8\xA7\xD9\x81\xD8\xAA \xD8\xB4\xD8\xAF. \xD8\xAD\xD8\xA7\xD9\x84\xD8\xA7 \xDA\xA9\xD9\x87 \xD9\x85\xD8\xAA\xD9\x86 \xD9\x85\xD9\x88\xD8\xB1\xD8\xAF \xD9\x86\xD8\xB8\xD8\xB1 \xD8\xA8\xD8\xB1\xD8\xA7\xDB\x8C \xD9\x88\xDB\x8C\xD8\xB1\xD8\xA7\xDB\x8C\xD8\xB4 \xD8\xB1\xD8\xA7 \xD8\xA8\xD9\x86\xD9\x88\xDB\x8C\xD8\xB3\xDB\x8C\xD8\xAF:");
        } catch (\Throwable $e) {
            Logger::error('Img2ImgHandler: storePhotoData failed', ['user_id' => $userId, 'error' => $e->getMessage()]);
            $this->baleClient->sendMessage($chatId, "\xE2\x9A\xA0\xEF\xB8\x8F \xD8\xAE\xD8\xB7\xD8\xA7\xDB\x8C\xDB\x8C \xD8\xAF\xD8\xB1 \xD8\xB0\xD8\xAE\xDB\x8C\xD8\xB1\xD9\x87 \xD8\xB9\xDA\xA9\xD8\xB3 \xD8\xB1\xD8\xAE \xD8\xAF\xD8\xA7\xD8\xAF. \xD9\x84\xD8\xB7\xD9\x81\xD8\xA7\xD9\x8B \xD8\xAF\xD9\x88\xD8\xA8\xD8\xA7\xD8\xB1\xD9\x87 \xD8\xAA\xD9\x84\xD8\xA7\xD8\xB4 \xDA\xA9\xD9\x86\xDB\x8C\xD8\xAF.");
        }
    }

    private function processEdit(int $chatId, int $userId, string $prompt, string $photoBase64): void
    {
        if (empty(trim($prompt))) {
            $this->baleClient->sendMessage($chatId, "\xE2\x9A\xA0\xEF\xB8\x8F \xD9\x84\xD8\xB7\xD9\x81\xD8\xA7\xD9\x8B \xDB\x8C\xDA\xA9 \xD9\x85\xD8\xAA\xD9\x86 \xD9\x85\xD8\xB9\xD8\xAA\xD8\xA8\xD8\xB1 \xD9\x88\xD8\xA7\xD8\xB1\xD8\xAF \xDA\xA9\xD9\x86\xDB\x8C\xD8\xAF.");
            return;
        }

        $user = User::findByBaleId($userId);
        if (!$user) {
            $this->baleClient->sendMessage($chatId, "\xE2\x9A\xA0\xEF\xB8\x8F \xDA\xA9\xD8\xA7\xD8\xB1\xD8\xA8\xD8\xB1 \xDB\x8C\xD8\xA7\xD9\x81\xD8\xAA \xD9\x86\xD8\xB4\xD8\xAF.");
            return;
        }
        $internalId = (int) $user['id'];

        $this->clearUserStateById($internalId);

        $aiService = new AIService();
        $model = $aiService->getFirstActiveModel();
        if (!$model) {
            Logger::error('Img2ImgHandler: no active AI model found');
            $this->baleClient->sendMessage($chatId, "\xE2\x9D\x8C \xD9\x85\xD8\xAF\xD9\x84 \xD9\x81\xD8\xB9\xD8\xA7\xD9\x84\xDB\x8C \xDB\x8C\xD8\xA7\xD9\x81\xD8\xAA \xD9\x86\xD8\xB4\xD8\xAF\xD8\x8C \xD9\x84\xD8\xB7\xD9\x81\xD8\xA7\xD9\x8B \xD8\xA8\xD8\xB9\xD8\xAF\xD8\xA7\xD9\x8B \xD8\xAA\xD9\x84\xD8\xA7\xD8\xB4 \xDA\xA9\xD9\x86\xDB\x8C\xD8\xAF.");
            return;
        }

        $cost = (int) $model['cost_per_image'];

        if (!CreditService::hasEnoughCredit($internalId, $cost)) {
            $this->baleClient->sendMessage($chatId, "\xE2\x9D\x8C \xD8\xA7\xD8\xB9\xD8\xAA\xD8\xA8\xD8\xA7\xD8\xB1 \xD8\xB4\xD9\x85\xD8\xA7 \xDA\xA9\xD8\xA7\xD9\x81\xDB\x8C \xD9\x86\xDB\x8C\xD8\xB3\xD8\xAA.\n\xF0\x9F\x92\xB0 \xD9\x87\xD8\xB2\xDB\x8C\xD9\x86\xD9\x87 \xD9\x87\xD8\xB1 \xD9\x88\xDB\x8C\xD8\xB1\xD8\xA7\xDB\x8C\xD8\xB4: {$cost} \xD8\xA7\xD8\xB9\xD8\xAA\xD8\xA8\xD8\xA7\xD8\xB1");
            return;
        }

        $referenceId = 'ai_img_' . $internalId . '_' . time() . '_' . bin2hex(random_bytes(4));

        $this->baleClient->sendMessage($chatId, "\xE2\x8F\xB3 \xD8\xAF\xD8\xB1 \xD8\xAD\xD8\xA7\xD9\x84 \xD9\x88\xDB\x8C\xD8\xB1\xD8\xA7\xDB\x8C\xD8\xB4 \xD8\xAA\xD8\xB5\xD9\x88\xDB\x8C\xD8\xB1... \xD9\x84\xD8\xB7\xD9\x81\xD8\xA7\xD9\x8B \xDA\x86\xD9\x86\xD8\xAF \xD9\x84\xD8\xAD\xD8\xB8\xD9\x87 \xD8\xB5\xD8\xA8\xD8\xB1 \xDA\xA9\xD9\x86\xDB\x8C\xD8\xAF.");

        $result = $aiService->generate([
            'model'  => $model['name'],
            'prompt' => $prompt,
            'image'  => $photoBase64,
        ]);

        if (isset($result['error'])) {
            Logger::error('Img2ImgHandler: AI edit failed', [
                'user_id' => $userId,
                'model'   => $model['name'],
                'error'   => $result['error'],
            ]);
            $this->baleClient->sendMessage($chatId, "\xE2\x9A\xA0\xEF\xB8\x8F \xD9\x85\xD8\xAA\xD8\xA3\xD8\xB3\xD9\x81\xD8\xA7\xD9\x86\xD9\x87 \xD9\x85\xD8\xB4\xDA\xA9\xD9\x84\xDB\x8C \xD8\xAF\xD8\xB1 \xD9\x88\xDB\x8C\xD8\xB1\xD8\xA7\xDB\x8C\xD8\xB4 \xD8\xAA\xD8\xB5\xD9\x88\xDB\x8C\xD8\xB1 \xD9\xBE\xDB\x8C\xD8\xB4 \xD8\xA2\xD9\x85\xD8\xAF. \xD9\x84\xD8\xB7\xD9\x81\xD8\xA7\xD9\x8B \xD8\xAF\xD9\x88\xD8\xA8\xD8\xA7\xD8\xB1\xD9\x87 \xD8\xAA\xD9\x84\xD8\xA7\xD8\xB4 \xDA\xA9\xD9\x86\xDB\x8C\xD8\xAF.");
            $this->logAiRequest($internalId, (int) $model['id'], $prompt, 'img2img', 'failed', $referenceId);
            return;
        }

        $images = $result['images'];
        $caption = "\xE2\x9C\x85 \xD9\x88\xDB\x8C\xD8\xB1\xD8\xA7\xDB\x8C\xD8\xB4 \xD8\xB4\xD8\xAF \xD8\xA8\xD8\xA7 \xD9\x85\xD8\xAF\xD9\x84 {$model['name']}\n\xF0\x9F\x92\xB0 \xD9\x87\xD8\xB2\xDB\x8C\xD9\x86\xD9\x87: {$cost} \xD8\xA7\xD8\xB9\xD8\xAA\xD8\xA8\xD8\xA7\xD8\xB1";

        $allSent = true;
        foreach ($images as $url) {
            $response = $this->baleClient->sendPhoto($chatId, $url, $caption);
            if (!isset($response['ok']) || $response['ok'] !== true) {
                $allSent = false;
                Logger::error('Img2ImgHandler: sendPhoto failed', [
                    'user_id' => $userId,
                    'url'     => $url,
                ]);
            }
            $caption = null;
        }

        if ($allSent) {
            $deducted = CreditService::deduct($internalId, $cost, $referenceId);
            if (!$deducted) {
                Logger::error('Img2ImgHandler: credit deduction failed', [
                    'user_id'      => $internalId,
                    'amount'       => $cost,
                    'reference_id' => $referenceId,
                ]);
            }
        }

        $this->logAiRequest($internalId, (int) $model['id'], $prompt, 'img2img', 'success', $referenceId);

        $this->clearUserStateById($internalId);

        // Send inline main menu
        $inlineKeyboard = [
            'inline_keyboard' => [
                [
                    ['text' => "\xF0\x9F\x8E\xA8 \xD8\xB3\xD8\xA7\xD8\xAE\xD8\xAA \xD8\xAA\xD8\xB5\xD9\x88\xDB\x8C\xD8\xB1", 'callback_data' => 'generate_image'],
                    ['text' => "\xF0\x9F\x96\xBC \xD9\x88\xDB\x8C\xD8\xB1\xD8\xA7\xDB\x8C\xD8\xB4 \xD8\xB9\xDA\xA9\xD8\xB3", 'callback_data' => 'edit_image']
                ],
                [
                    ['text' => "\xF0\x9F\x91\xA4 \xD8\xAD\xD8\xB3\xD8\xA7\xD8\xA8 \xD9\x85\xD9\x86", 'callback_data' => 'account'],
                    ['text' => "\xF0\x9F\x92\xB3 \xD8\xB4\xD8\xA7\xD8\xB1\xDA\x98 \xD8\xA7\xD8\xB9\xD8\xAA\xD8\xA8\xD8\xA7\xD8\xB1", 'callback_data' => 'buy_credit']
                ],
                [
                    ['text' => "\xE2\x9D\x93 \xD8\xB1\xD8\xA7\xD9\x87\xD9\x86\xD9\x85\xD8\xA7", 'callback_data' => 'help']
                ]
            ]
        ];
        $this->baleClient->sendMessage($chatId, "\xE2\x9C\x85 \xD8\xAA\xD8\xB5\xD9\x88\xDB\x8C\xD8\xB1 \xD8\xA8\xD8\xA7 \xD9\x85\xD9\x88\xD9\x81\xD9\x82\xDB\x8C\xD8\xAA \xD9\x88\xDB\x8C\xD8\xB1\xD8\xA7\xDB\x8C\xD8\xB4 \xD8\xB4\xD8\xAF!", $inlineKeyboard);
    }

    private function downloadPhotoAsBase64(string $fileId): ?string
    {
        try {
            $fileInfo = $this->baleClient->getFile($fileId);
            if (!$fileInfo || !isset($fileInfo['file_path'])) {
                Logger::error('Img2ImgHandler: getFile failed', ['user_id' => 0, 'file_id' => $fileId]);
                return null;
            }
            $fileUrl = $fileInfo['file_path'];
            $imageData = @file_get_contents($fileUrl);
            if ($imageData === false) return null;
            return base64_encode($imageData);
        } catch (\Throwable $e) {
            Logger::error('Img2ImgHandler: downloadPhoto failed', ['error' => $e->getMessage()]);
            return null;
        }
    }

    private function getUserState(int $baleUserId): ?string
    {
        try {
            $db = Database::getInstance();
            $stmt = $db->query(
                "SELECT bs.state FROM bot_state bs 
                 JOIN users u ON bs.user_id = u.id 
                 WHERE u.bale_user_id = ?",
                [$baleUserId]
            );
            $row = $stmt->fetch();
            return $row['state'] ?? null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function getStoredPhotoData(int $baleUserId): ?string
    {
        try {
            $db = Database::getInstance();
            $stmt = $db->query(
                "SELECT bs.photo_base64 FROM bot_state bs 
                 JOIN users u ON bs.user_id = u.id 
                 WHERE u.bale_user_id = ? AND bs.state = 'awaiting_edit_prompt'",
                [$baleUserId]
            );
            $row = $stmt->fetch();
            return $row['photo_base64'] ?? null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function clearUserStateById(int $internalId): void
    {
        try {
            $db = Database::getInstance();
            $db->query("UPDATE bot_state SET photo_base64 = NULL, state = 'idle' WHERE user_id = ?", [$internalId]);
        } catch (\Throwable $e) {
            // Silent
        }
    }

    private function getPersistentKeyboard(): array
    {
        return [
            'keyboard' => [
                [['text' => '/cancel'], ['text' => "\xD9\x85\xD9\x86\xD9\x88 \xD8\xA7\xD8\xB5\xD9\x84\xDB\x8C"]]
            ],
            'resize_keyboard' => true
        ];
    }

    private function logAiRequest(int $userId, int $modelId, string $prompt, string $imageType, string $status, string $referenceId): void
    {
        try {
            $db = Database::getInstance();
            $db->query(
                "INSERT INTO ai_requests (user_id, model_id, prompt, image_type, status, reference_id) VALUES (?, ?, ?, ?, ?, ?)",
                [$userId, $modelId, $prompt, $imageType, $status, $referenceId]
            );
        } catch (\Throwable $e) {
            Logger::error('Img2ImgHandler: logAiRequest failed', ['error' => $e->getMessage()]);
        }
    }
}
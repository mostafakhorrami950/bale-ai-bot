<?php

namespace Modules\Bot\Handlers;

use Core\Config;
use Modules\AI\VideoService;
use Modules\Bot\CreditService;
use Modules\Bot\Models\User;
use Database\Database;
use Database\Logger;

class VideoHandler extends BaseHandler
{
    private string $tempDir;

    public function __construct($baleClient)
    {
        parent::__construct($baleClient);
        $this->tempDir = Config::get('BASE_PATH', __DIR__ . '/../../..') . '/uploads/videos/';
        if (!is_dir($this->tempDir)) {
            @mkdir($this->tempDir, 0755, true);
        }
    }

    public function handle($update): void
    {
        try {
            $chatId = $update->getChatId();
            $userId = $update->getUserId();
            $text = $update->getText();
            $callbackData = $update->getCallbackData();
            $isCallback = $update->isCallback();

            if (!$this->checkMembership($userId, $chatId)) return;

            $state = $this->getUserState($userId);

            // Entry: generate_video button or text
            if ($callbackData === 'generate_video' || $text === "\xF0\x9F\x8E\xAC ساخت ویدئو با هوش مصنوعی") {
                $this->showModelSelection($chatId, $userId);
                return;
            }

            // Model selection callback: vid_select_model_{id}
            if ($isCallback && is_string($callbackData) && str_starts_with($callbackData, 'vid_select_model_')) {
                $modelId = (int) str_replace('vid_select_model_', '', $callbackData);
                $this->saveModelAndShowPrompt($chatId, $userId, $modelId);
                return;
            }

            // Prompt input
            if ($state === 'awaiting_video_prompt') {
                if ($text === '/cancel' || $text === 'منو اصلی') {
                    $this->clearState($userId);
                    return;
                }
                $this->processPrompt($chatId, $userId, $text);
                return;
            }

            // Resolution selection: vid_res_{modelId}_{resolution}
            if ($isCallback && is_string($callbackData) && str_starts_with($callbackData, 'vid_res_')) {
                $this->handleResolutionCallback($chatId, $userId, $callbackData);
                return;
            }

            // Aspect ratio selection: vid_ar_{modelId}_{aspectRatio}
            if ($isCallback && is_string($callbackData) && str_starts_with($callbackData, 'vid_ar_')) {
                $this->handleAspectRatioCallback($chatId, $userId, $callbackData);
                return;
            }

            // Duration selection: vid_dur_{modelId}_{duration}_{resolution}_{aspectRatio}
            if ($isCallback && is_string($callbackData) && str_starts_with($callbackData, 'vid_dur_')) {
                $this->handleDurationCallback($chatId, $userId, $callbackData);
                return;
            }

            // Confirm and send: vid_confirm_{modelId}_{duration}_{resolution}_{aspectRatio}
            if ($isCallback && is_string($callbackData) && str_starts_with($callbackData, 'vid_confirm_')) {
                $this->handleConfirmCallback($chatId, $userId, $callbackData);
                return;
            }

            // Back to selection
            if ($callbackData === 'vid_back_model') {
                $this->showModelSelection($chatId, $userId);
                return;
            }

            // Fallback
            $this->baleClient->sendMessage($chatId, "🎬 لطفاً از منو گزینه «ساخت ویدئو با هوش مصنوعی» را انتخاب کنید.");
        } catch (\Throwable $e) {
            error_log("VideoHandler FATAL: " . $e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine());
            if (isset($chatId)) {
                $this->baleClient->sendMessage($chatId, "⚠️ خطایی رخ داد. مجدداً تلاش کنید.");
            }
        }
    }

    /**
     * Show video model selection.
     */
    private function showModelSelection(int $chatId, int $userId): void
    {
        try {
            $db = Database::getInstance();
            $models = $db->query(
                "SELECT id, name, display_name, description, cost_per_video, is_active FROM ai_video_models WHERE is_active = 1 ORDER BY id ASC"
            )->fetchAll();

            if (empty($models)) {
                $this->baleClient->sendMessage($chatId, "❌ هیچ مدل ویدئویی فعالی یافت نشد.");
                return;
            }

            $msg = "🎬 **ساخت ویدئو با هوش مصنوعی**\n\nمدل مورد نظر خود را انتخاب کنید:\n\n";
            $keyboard = ['inline_keyboard' => []];

            foreach ($models as $m) {
                $display = $m['display_name'] ?? $m['name'];
                $desc = $m['description'] ? "\n  📌 {$m['description']}" : '';
                $cost = $m['cost_per_video'];
                $msg .= "• **{$display}**\n  💰 هزینه: {$cost} اعتبار{$desc}\n\n";
                $keyboard['inline_keyboard'][] = [
                    ['text' => $display, 'callback_data' => 'vid_select_model_' . $m['id']]
                ];
            }

            $keyboard['inline_keyboard'][] = [['text' => '🔙 بازگشت', 'callback_data' => 'start_chat']];

            $this->baleClient->sendMessage($chatId, $msg, $keyboard);
        } catch (\Throwable $e) {
            error_log("VideoHandler::showModelSelection ERROR: " . $e->getMessage());
            $this->baleClient->sendMessage($chatId, "⚠️ خطا در دریافت لیست مدل‌ها.");
        }
    }

    /**
     * Save selected model and ask for prompt text.
     */
    private function saveModelAndShowPrompt(int $chatId, int $userId, int $modelId): void
    {
        try {
            $db = Database::getInstance();
            $model = $db->query(
                "SELECT * FROM ai_video_models WHERE id = ? AND is_active = 1",
                [$modelId]
            )->fetch();

            if (!$model) {
                $this->baleClient->sendMessage($chatId, "❌ مدل یافت نشد.");
                return;
            }

            // Save in bot_state
            $internalId = $this->resolveUserId($userId);
            if (!$internalId) {
                $this->baleClient->sendMessage($chatId, "⚠️ کاربر یافت نشد. لطفاً /start را بزنید.");
                return;
            }

            $extra = json_encode([
                'video_model_id' => $modelId,
                'video_model_name' => $model['name'],
                'video_cost' => (int) ($model['cost_per_video'] ?? 5),
                'step' => 'prompt',
            ]);
            $db->query("REPLACE INTO bot_state (user_id, state, extra_data) VALUES (?, 'awaiting_video_prompt', ?)", [$internalId, $extra]);

            $displayName = $model['display_name'] ?? $model['name'];
            $msg = "🎬 **مدل «{$displayName}» انتخاب شد.**\n\n"
                 . "✏️ لطفاً متن (پرامپت) ویدئوی خود را بنویسید:\n\n"
                 . "مثال:\n"
                 . "• یک سگ طلایی در ساحل آفتابی در حال بازی با توپ\n"
                 . "• غروب آفتاب بر فراز کوه‌های آلپ با دوربین آهسته\n\n"
                 . "💡 هرچه توضیحات دقیق‌تر باشد، نتیجه بهتر است.\n"
                 . "📝 /cancel برای لغو";

            $this->baleClient->sendMessage($chatId, $msg);
        } catch (\Throwable $e) {
            error_log("VideoHandler::saveModelAndShowPrompt ERROR: " . $e->getMessage());
            $this->baleClient->sendMessage($chatId, "⚠️ خطا در ذخیره مدل.");
        }
    }

    /**
     * Process the prompt text, then show optional settings (resolution, aspect_ratio, duration).
     */
    private function processPrompt(int $chatId, int $userId, string $prompt): void
    {
        $db = Database::getInstance();
        $internalId = $this->resolveUserId($userId);
        if (!$internalId) return;

        $stateData = $db->query("SELECT extra_data FROM bot_state WHERE user_id = ?", [$internalId])->fetch();
        if (!$stateData) {
            $this->baleClient->sendMessage($chatId, "⚠️ اطلاعات جلسه یافت نشد. دوباره تلاش کنید.");
            return;
        }

        $extra = json_decode($stateData['extra_data'] ?? '{}', true);
        $modelId = (int) ($extra['video_model_id'] ?? 0);

        // Save prompt in state
        $extra['prompt'] = $prompt;
        $extra['step'] = 'settings';
        $db->query("REPLACE INTO bot_state (user_id, state, extra_data) VALUES (?, 'awaiting_video_prompt', ?)", [$internalId, json_encode($extra)]);

        // Show settings based on model capabilities
        $this->showVideoSettings($chatId, $userId, $modelId, $prompt);
    }

    /**
     * Show optional settings for the video (only what model supports).
     */
    private function showVideoSettings(int $chatId, int $userId, int $modelId, string $prompt): void
    {
        try {
            $db = Database::getInstance();
            $model = $db->query("SELECT * FROM ai_video_models WHERE id = ?", [$modelId])->fetch();
            if (!$model) return;

            $msg = "🎬 **تنظیمات ویدئو**\n\n"
                 . "📝 پرامپت شما: " . mb_substr($prompt, 0, 100) . (mb_strlen($prompt) > 100 ? '...' : '') . "\n\n";

            $keyboard = ['inline_keyboard' => []];

            // Resolutions
            $resolutions = $this->parseCsv($model['supported_resolutions'] ?? '');
            if (!empty($resolutions)) {
                $msg .= "📐 **resolution را انتخاب کنید:**\n" . implode(' | ', $resolutions) . "\n\n";
                $row = [];
                foreach ($resolutions as $r) {
                    $row[] = ['text' => $r, 'callback_data' => 'vid_res_' . $modelId . '_' . $r];
                }
                // Split into rows of 3
                $keyboard['inline_keyboard'] = array_merge($keyboard['inline_keyboard'], array_chunk($row, 3));
            }

            // If no resolutions, go to aspect ratios
            if (empty($resolutions)) {
                $this->showAspectRatios($chatId, $userId, $modelId, $prompt);
                return;
            }

            // Save step=waiting_resolution
            $msg .= "لطفاً resolution مورد نظر را انتخاب کنید:";
            $keyboard['inline_keyboard'][] = [['text' => '🔙 بازگشت', 'callback_data' => 'vid_back_model']];

            $this->baleClient->sendMessage($chatId, $msg, $keyboard);
        } catch (\Throwable $e) {
            error_log("VideoHandler::showVideoSettings ERROR: " . $e->getMessage());
            $this->baleClient->sendMessage($chatId, "⚠️ خطا در بارگذاری تنظیمات.");
        }
    }

    private function handleResolutionCallback(int $chatId, int $userId, string $callbackData): void
    {
        // format: vid_res_{modelId}_{resolution}_{aspectRatio}_{duration} (some parts optional)
        $rest = substr($callbackData, 8); // remove "vid_res_"
        $parts = explode('_', $rest);
        $modelId = (int) ($parts[0] ?? 0);
        $resolution = $parts[1] ?? '';

        if (empty($resolution)) return;

        $db = Database::getInstance();
        $model = $db->query("SELECT * FROM ai_video_models WHERE id = ?", [$modelId])->fetch();
        if (!$model) return;

        // Save selected resolution in state
        $internalId = $this->resolveUserId($userId);
        if (!$internalId) return;
        $stateData = $db->query("SELECT extra_data FROM bot_state WHERE user_id = ?", [$internalId])->fetch();
        $extra = json_decode($stateData['extra_data'] ?? '{}', true);
        $extra['resolution'] = $resolution;
        $extra['step'] = 'waiting_aspect_ratio';
        $db->query("REPLACE INTO bot_state (user_id, state, extra_data) VALUES (?, 'awaiting_video_prompt', ?)", [$internalId, json_encode($extra)]);

        $this->showAspectRatios($chatId, $userId, $modelId, $extra['prompt'] ?? '');
    }

    private function showAspectRatios(int $chatId, int $userId, int $modelId, string $prompt): void
    {
        $db = Database::getInstance();
        $model = $db->query("SELECT * FROM ai_video_models WHERE id = ?", [$modelId])->fetch();
        if (!$model) return;

        $aspectRatios = $this->parseCsv($model['supported_aspect_ratios'] ?? '');

        if (empty($aspectRatios)) {
            // Skip to durations
            $this->showDurations($chatId, $userId, $modelId, $prompt, '');
            return;
        }

        $msg = "🎬 **انتخاب aspect ratio**\n\n"
             . "📝 پرامپت: " . mb_substr($prompt, 0, 100) . (mb_strlen($prompt) > 100 ? '...' : '') . "\n\n"
             . "📐 نسبت تصویر را انتخاب کنید:\n";
        $keyboard = ['inline_keyboard' => []];
        $row = [];
        foreach ($aspectRatios as $ar) {
            $row[] = ['text' => $ar, 'callback_data' => 'vid_ar_' . $modelId . '_' . $ar];
        }
        $keyboard['inline_keyboard'] = array_chunk($row, 3);
        $keyboard['inline_keyboard'][] = [['text' => '🔙 بازگشت', 'callback_data' => 'vid_back_model']];

        $this->baleClient->sendMessage($chatId, $msg, $keyboard);
    }

    private function handleAspectRatioCallback(int $chatId, int $userId, string $callbackData): void
    {
        $rest = substr($callbackData, 7); // remove "vid_ar_"
        $parts = explode('_', $rest);
        $modelId = (int) ($parts[0] ?? 0);
        $aspectRatio = $parts[1] ?? '';

        if (empty($aspectRatio)) return;

        $db = Database::getInstance();
        $internalId = $this->resolveUserId($userId);
        if (!$internalId) return;
        $stateData = $db->query("SELECT extra_data FROM bot_state WHERE user_id = ?", [$internalId])->fetch();
        $extra = json_decode($stateData['extra_data'] ?? '{}', true);
        $extra['aspect_ratio'] = $aspectRatio;
        $extra['step'] = 'waiting_duration';
        $db->query("REPLACE INTO bot_state (user_id, state, extra_data) VALUES (?, 'awaiting_video_prompt', ?)", [$internalId, json_encode($extra)]);

        $this->showDurations($chatId, $userId, $modelId, $extra['prompt'] ?? '', $extra['resolution'] ?? '');
    }

    private function showDurations(int $chatId, int $userId, int $modelId, string $prompt, string $resolution): void
    {
        $db = Database::getInstance();
        $model = $db->query("SELECT * FROM ai_video_models WHERE id = ?", [$modelId])->fetch();
        if (!$model) return;

        $durations = $this->parseCsv($model['supported_durations'] ?? '');

        if (empty($durations)) {
            $durations = range(3, 30);
        }

        $msg = "🎬 **انتخاب مدت زمان ویدئو**\n\n"
             . "📝 پرامپت: " . mb_substr($prompt, 0, 100) . (mb_strlen($prompt) > 100 ? '...' : '') . "\n";
        if ($resolution) $msg .= "📐 resolution: {$resolution}\n";
        $msg .= "\n⏱️ مدت زمان مورد نظر را انتخاب کنید (ثانیه):\n";
        $keyboard = ['inline_keyboard' => []];

        // Chunk durations into rows of 5
        $durationsInt = array_map('intval', $durations);
        sort($durationsInt);
        $chunks = array_chunk($durationsInt, 5);
        foreach ($chunks as $chunk) {
            $row = [];
            foreach ($chunk as $d) {
                $row[] = ['text' => $d . 's', 'callback_data' => 'vid_dur_' . $modelId . '_' . $d];
            }
            $keyboard['inline_keyboard'][] = $row;
        }
        $keyboard['inline_keyboard'][] = [['text' => '🔙 بازگشت', 'callback_data' => 'vid_back_model']];

        $this->baleClient->sendMessage($chatId, $msg, $keyboard);
    }

    private function handleDurationCallback(int $chatId, int $userId, string $callbackData): void
    {
        $rest = substr($callbackData, 9); // remove "vid_dur_"
        $parts = explode('_', $rest);
        $modelId = (int) ($parts[0] ?? 0);
        $duration = (int) ($parts[1] ?? 5);

        $db = Database::getInstance();
        $internalId = $this->resolveUserId($userId);
        if (!$internalId) return;
        $stateData = $db->query("SELECT extra_data FROM bot_state WHERE user_id = ?", [$internalId])->fetch();
        $extra = json_decode($stateData['extra_data'] ?? '{}', true);
        $extra['duration'] = $duration;
        $extra['step'] = 'confirm';
        $db->query("REPLACE INTO bot_state (user_id, state, extra_data) VALUES (?, 'awaiting_video_prompt', ?)", [$internalId, json_encode($extra)]);

        $this->showConfirm($chatId, $userId, $extra['prompt'] ?? '', $modelId, $duration, $extra['resolution'] ?? '', $extra['aspect_ratio'] ?? '');
    }

    private function showConfirm(int $chatId, int $userId, string $prompt, int $modelId, int $duration, string $resolution, string $aspectRatio): void
    {
        $db = Database::getInstance();
        $model = $db->query("SELECT id, name, display_name, cost_per_video, cost_per_second FROM ai_video_models WHERE id = ?", [$modelId])->fetch();
        if (!$model) return;

        $displayName = $model['display_name'] ?? $model['name'];
        $costPerSecond = (int) ($model['cost_per_second'] ?? 0);
        $baseCost = (int) ($model['cost_per_video'] ?? 5);
        // Cost = base + (duration * cost_per_second), but at least base cost
        $cost = $costPerSecond > 0 ? ($baseCost + ($duration * $costPerSecond)) : $baseCost;

        $msg = "🎬 **تایید نهایی**\n\n"
             . "📝 پرامپت: {$prompt}\n"
             . "🤖 مدل: {$displayName}\n"
             . "⏱️ مدت: {$duration} ثانیه\n";
        if ($resolution) $msg .= "📐 resolution: {$resolution}\n";
        if ($aspectRatio) $msg .= "📐 نسبت تصویر: {$aspectRatio}\n";
        $msg .= "💰 **هزینه: {$cost} اعتبار**\n\n"
              . "✅ برای ارسال، دکمه زیر را بزنید.";

        $keyboard = [
            'inline_keyboard' => [
                [['text' => '✅ ارسال به هوش مصنوعی', 'callback_data' => 'vid_confirm_' . $modelId . '_' . $duration . '_' . ($resolution ?: 'auto') . '_' . ($aspectRatio ?: 'auto')]],
                [['text' => '🔙 ویرایش تنظیمات', 'callback_data' => 'vid_back_model']],
            ]
        ];

        $this->baleClient->sendMessage($chatId, $msg, $keyboard);
    }

    private function handleConfirmCallback(int $chatId, int $userId, string $callbackData): void
    {
        // format: vid_confirm_{modelId}_{duration}_{resolution}_{aspectRatio}
        $rest = substr($callbackData, 13); // remove "vid_confirm_"
        $parts = explode('_', $rest);
        $modelId = (int) ($parts[0] ?? 0);
        $duration = (int) ($parts[1] ?? 5);
        $resolution = $parts[2] ?? '';
        $aspectRatio = $parts[3] ?? '';

        $db = Database::getInstance();
        $internalId = $this->resolveUserId($userId);
        if (!$internalId) return;

        $model = $db->query("SELECT * FROM ai_video_models WHERE id = ?", [$modelId])->fetch();
        if (!$model) {
            $this->baleClient->sendMessage($chatId, "❌ مدل یافت نشد.");
            return;
        }

        $cost = (int) ($model['cost_per_video'] ?? 5);

        // Check credit
        if (!CreditService::hasEnoughCredit($internalId, $cost)) {
            $buyCreditKeyboard = [
                'inline_keyboard' => [
                    [['text' => "\xF0\x9F\x92\xB3 برای افزایش اعتبار کلیک کن", 'callback_data' => 'buy_credit']],
                ]
            ];
            $this->baleClient->sendMessage($chatId, "❌ **اعتبار کافی نیست!**\n\nشما به {$cost} اعتبار نیاز دارید.\nلطفاً از بخش «💳 خرید اعتبار» حساب خود را شارژ کنید.", $buyCreditKeyboard);
            return;
        }

        // Get prompt from state
        $stateData = $db->query("SELECT extra_data FROM bot_state WHERE user_id = ?", [$internalId])->fetch();
        $extra = json_decode($stateData['extra_data'] ?? '{}', true);
        $prompt = $extra['prompt'] ?? '';

        if (empty($prompt)) {
            $this->baleClient->sendMessage($chatId, "⚠️ پرامپت یافت نشد. دوباره تلاش کنید.");
            return;
        }

        // Send "processing" message
        $this->baleClient->sendMessage($chatId, "⏳ در حال ارسال درخواست به هوش مصنوعی...");

        // Mark processing state
        $extra['step'] = 'processing';
        $db->query("REPLACE INTO bot_state (user_id, state, extra_data) VALUES (?, 'vid_processing', ?)", [$internalId, json_encode($extra)]);

        // Build payload
        $params = [
            'model' => $model['name'],
            'prompt' => $prompt,
            'duration' => $duration,
        ];
        if (!empty($resolution) && $resolution !== 'auto') $params['resolution'] = $resolution;
        if (!empty($aspectRatio) && $aspectRatio !== 'auto') $params['aspect_ratio'] = $aspectRatio;

        // Submit to OpenRouter
        $videoService = new VideoService();
        $result = $videoService->submit($params);

        if (isset($result['error'])) {
            // Clear processing state
            $db->query("DELETE FROM bot_state WHERE user_id = ?", [$internalId]);
            $this->baleClient->sendMessage($chatId, "❌ خطا: " . $result['error']);
            Logger::error('VideoHandler::submit error', ['user_id' => $internalId, 'error' => $result['error']]);
            return;
        }

        $jobId = $result['job_id'];
        $pollingUrl = $result['polling_url'];

        // Save job info in state
        $extra['job_id'] = $jobId;
        $extra['polling_url'] = $pollingUrl;
        $db->query("REPLACE INTO bot_state (user_id, state, extra_data) VALUES (?, 'vid_polling', ?)", [$internalId, json_encode($extra)]);

        // Send initial status
        $this->baleClient->sendMessage($chatId, "✅ درخواست شما ثبت شد.\n🆔 شناسه: {$jobId}\n⏳ در حال ساخت ویدئو... این فرآیند ممکن است چند دقیقه طول بکشد.");

        // Start polling in background (first check after 5 seconds)
        $this->pollVideoJob($chatId, $internalId, $pollingUrl, $cost, $model['name'], $prompt);
    }

    /**
     * Poll the video job and send result back to user.
     */
    private function pollVideoJob(int $chatId, int $internalId, string $pollingUrl, int $cost, string $modelName, string $prompt): void
    {
        $videoService = new VideoService();
        $maxAttempts = 20; // 20 * 5 = 100 seconds max
        $attempt = 0;

        while ($attempt < $maxAttempts) {
            $attempt++;
            sleep(5); // Wait 5 seconds between polls

            $result = $videoService->poll($pollingUrl);

            if (isset($result['error'])) {
                $this->baleClient->sendMessage($chatId, "❌ خطا در بررسی وضعیت: " . $result['error']);
                Logger::error('VideoHandler::poll error', ['user_id' => $internalId, 'error' => $result['error']]);
                return;
            }

            if ($result['status'] === 'completed') {
                // Download video
                $urls = $result['unsigned_urls'] ?? [];
                if (empty($urls)) {
                    $this->baleClient->sendMessage($chatId, "⚠️ ویدئو ساخته شد اما لینک دانلودی یافت نشد.");
                    return;
                }

                // Deduct credit
                $refId = 'video_' . $internalId . '_' . time();
                CreditService::deduct($internalId, $cost, $refId);

                // Send each video to user
                $sentCount = count($urls);
                // Send download URLs as text since Bale API may not support file upload
                $downloadMsg = "🔗 **لینک‌های دانلود ویدئو:**\n\n";
                foreach ($urls as $i => $url) {
                    $num = $i + 1;
                    $downloadMsg .= "{$num}. [دانلود ویدئو {$num}]({$url})\n";
                }
                $this->baleClient->sendMessage($chatId, $downloadMsg);

                // Success message
                $msg = "✅ **ویدئو ساخته شد!**\n\n"
                     . "🤖 مدل: {$modelName}\n"
                     . "💰 هزینه کسر شده: {$cost} اعتبار\n"
                     . "🔖 reference: {$refId}";
                $this->baleClient->sendMessage($chatId, $msg);

                // Log
                Logger::info('VideoHandler::completed', [
                    'user_id' => $internalId,
                    'model' => $modelName,
                    'cost' => $cost,
                    'ref_id' => $refId,
                    'sent_count' => $sentCount,
                ]);

                // Clear state
                $db = Database::getInstance();
                $db->query("DELETE FROM bot_state WHERE user_id = ?", [$internalId]);
                return;
            }

            if ($result['status'] === 'failed') {
                $this->baleClient->sendMessage($chatId, "❌ **ساخت ویدئو ناموفق بود.**\n\n" . ($result['error'] ?? 'خطای نامشخص'));
                Logger::error('VideoHandler::failed', [
                    'user_id' => $internalId,
                    'error' => $result['error'] ?? 'unknown',
                ]);
                $db = Database::getInstance();
                $db->query("DELETE FROM bot_state WHERE user_id = ?", [$internalId]);
                return;
            }

            // Still in progress — send update every 3 attempts (every 15 seconds)
            if ($attempt % 3 === 0) {
                $this->baleClient->sendMessage($chatId, "⏳ در حال ساخت ویدئو... (مدت انتظار: " . ($attempt * 5) . " ثانیه)");
            }
        }

        // Timeout
        $this->baleClient->sendMessage($chatId, "⏰ زمان انتظار به پایان رسید. ویدئو هنوز آماده نشده است.\n"
            . "لطفاً بعداً با پشتیبانی تماس بگیرید.\n"
            . "🆔 Job ID: " . basename($pollingUrl));
        Logger::error('VideoHandler::timeout', [
            'user_id' => $internalId,
            'polling_url' => $pollingUrl,
        ]);
    }

    // ─── Helpers ───

    private function parseCsv(?string $csv): array
    {
        if (empty($csv)) return [];
        $items = explode(',', $csv);
        $items = array_map('trim', $items);
        return array_filter($items, fn($v) => $v !== '');
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

    private function clearState(int $userId): void
    {
        try {
            $db = Database::getInstance();
            $internalId = $this->resolveUserId($userId);
            if ($internalId) {
                $db->query("DELETE FROM bot_state WHERE user_id = ?", [$internalId]);
            }
        } catch (\Throwable $e) {}
    }
}
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
            $hasPhoto = $update->hasPhoto();

            if (!$this->checkMembership($userId, $chatId)) return;

            $state = $this->getUserState($userId);

            // PRIORITY 1: Always check callbacks BEFORE state-based photo checks
            // Model selection callback: vid_select_model_{id}
            if ($isCallback && is_string($callbackData) && str_starts_with($callbackData, 'vid_select_model_')) {
                $modelId = (int) str_replace('vid_select_model_', '', $callbackData);
                $this->saveModelAndStartFlow($chatId, $userId, $modelId);
                return;
            }

            // Skip optional step: vid_skip_{step} — check BEFORE state photo checks
            if ($isCallback && is_string($callbackData) && str_starts_with($callbackData, 'vid_skip_')) {
                $this->handleSkipCallback($chatId, $userId, $callbackData);
                return;
            }

            // Duration selection: vid_dur_{duration}
            if ($isCallback && is_string($callbackData) && str_starts_with($callbackData, 'vid_dur_')) {
                $this->handleDurationCallback($chatId, $userId, $callbackData);
                return;
            }

            // Aspect ratio selection: vid_ar_{aspectRatio}
            if ($isCallback && is_string($callbackData) && str_starts_with($callbackData, 'vid_ar_')) {
                $this->handleAspectRatioCallback($chatId, $userId, $callbackData);
                return;
            }

            // Resolution selection: vid_res_{resolution}
            if ($isCallback && is_string($callbackData) && str_starts_with($callbackData, 'vid_res_')) {
                $this->handleResolutionCallback($chatId, $userId, $callbackData);
                return;
            }

            // Confirm and send: vid_confirm_{modelId}
            if ($isCallback && is_string($callbackData) && str_starts_with($callbackData, 'vid_confirm_')) {
                $this->handleConfirmCallback($chatId, $userId, $callbackData);
                return;
            }

            // Back to model selection
            if ($callbackData === 'vid_back_model') {
                $this->showModelSelection($chatId, $userId);
                return;
            }

            // PRIORITY 2: Entry: generate_video button or text
            if ($callbackData === 'generate_video' || $text === "\xF0\x9F\x8E\xAC ساخت ویدئو با هوش مصنوعی") {
                $this->showModelSelection($chatId, $userId);
                return;
            }

            // PRIORITY 3: State-based photo/prompt input checks
            // First frame photo input — only if hasPhoto, otherwise fall through
            if ($state === 'awaiting_video_first_frame') {
                if ($hasPhoto) {
                    $this->processFirstFrame($chatId, $userId, $update);
                } else {
                    $this->baleClient->sendMessage($chatId, "⚠️ لطفاً یک تصویر ارسال کنید.");
                }
                return;
            }

            // Last frame photo input
            if ($state === 'awaiting_video_last_frame') {
                if ($hasPhoto) {
                    $this->processLastFrame($chatId, $userId, $update);
                } else {
                    $this->baleClient->sendMessage($chatId, "⚠️ لطفاً یک تصویر ارسال کنید.");
                }
                return;
            }

            // Reference photo input
            if ($state === 'awaiting_video_reference') {
                if ($hasPhoto) {
                    $this->processReference($chatId, $userId, $update);
                } else {
                    $this->baleClient->sendMessage($chatId, "⚠️ لطفاً یک تصویر ارسال کنید.");
                }
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
                "SELECT id, name, display_name, description, cost_per_second, is_active FROM ai_video_models WHERE is_active = 1 ORDER BY id ASC"
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
                $costPerSec = (int) ($m['cost_per_second'] ?? 1);
                $msg .= "• **{$display}**\n  💰 هزینه: {$costPerSec} اعتبار/ثانیه{$desc}\n\n";
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
     * Save selected model and start the flow.
     * Flow order: first_frame → last_frame → reference → duration → aspect_ratio → resolution → prompt → confirm
     */
    private function saveModelAndStartFlow(int $chatId, int $userId, int $modelId): void
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

            $internalId = $this->resolveUserId($userId);
            if (!$internalId) {
                $this->baleClient->sendMessage($chatId, "⚠️ کاربر یافت نشد. لطفاً /start را بزنید.");
                return;
            }

            $extra = [
                'video_model_id' => $modelId,
                'video_model_name' => $model['name'],
                'cost_per_second' => (int) ($model['cost_per_second'] ?? 1),
                'allow_first_frame' => (int) ($model['allow_first_frame'] ?? 0),
                'allow_last_frame' => (int) ($model['allow_last_frame'] ?? 0),
                'allow_input_references' => (int) ($model['allow_input_references'] ?? 0),
                'supported_aspect_ratios' => $model['supported_aspect_ratios'] ?? '',
                'supported_resolutions' => $model['supported_resolutions'] ?? '',
                'supported_durations' => $model['supported_durations'] ?? '',
                'step' => 'started',
            ];

            // Move to first step based on model capabilities
            $this->advanceToNextStep($chatId, $userId, $internalId, $extra);
        } catch (\Throwable $e) {
            error_log("VideoHandler::saveModelAndStartFlow ERROR: " . $e->getMessage());
            $this->baleClient->sendMessage($chatId, "⚠️ خطا در ذخیره مدل.");
        }
    }

    /**
     * Advance to the next step in the video creation flow.
     * Correct order: first_frame → last_frame → reference → duration → aspect_ratio → resolution → prompt → confirm
     */
    private function advanceToNextStep(int $chatId, int $userId, int $internalId, array $extra): void
    {
        $db = Database::getInstance();
        $currentStep = $extra['step'] ?? 'started';

        // Determine next step
        $nextStep = $this->getNextStep($extra, $currentStep);

        if ($nextStep === 'confirm') {
            $extra['step'] = 'confirm';
            $db->query("REPLACE INTO bot_state (user_id, state, extra_data) VALUES (?, 'awaiting_video_prompt', ?)", [$internalId, json_encode($extra)]);
            $this->showConfirm($chatId, $userId, $internalId, $extra);
            return;
        }

        if ($nextStep === 'first_frame') {
            $extra['step'] = 'first_frame';
            $db->query("REPLACE INTO bot_state (user_id, state, extra_data) VALUES (?, 'awaiting_video_first_frame', ?)", [$internalId, json_encode($extra)]);
            $keyboard = [
                'inline_keyboard' => [
                    [['text' => '⏭️ نیازی ندارم', 'callback_data' => 'vid_skip_first_frame']],
                ]
            ];
            $this->baleClient->sendMessage($chatId, "🖼️ **تصویر فریم اول را ارسال کنید**\n\nیک تصویر برای فریم اول ویدئو ارسال کنید.\nیا دکمه «نیازی ندارم» را بزنید.", $keyboard);
            return;
        }

        if ($nextStep === 'last_frame') {
            $extra['step'] = 'last_frame';
            $db->query("REPLACE INTO bot_state (user_id, state, extra_data) VALUES (?, 'awaiting_video_last_frame', ?)", [$internalId, json_encode($extra)]);
            $keyboard = [
                'inline_keyboard' => [
                    [['text' => '⏭️ نیازی ندارم', 'callback_data' => 'vid_skip_last_frame']],
                ]
            ];
            $this->baleClient->sendMessage($chatId, "🖼️ **تصویر فریم آخر را ارسال کنید**\n\nیک تصویر برای فریم آخر ویدئو ارسال کنید.\nیا دکمه «نیازی ندارم» را بزنید.", $keyboard);
            return;
        }

        if ($nextStep === 'reference') {
            $extra['step'] = 'reference';
            $db->query("REPLACE INTO bot_state (user_id, state, extra_data) VALUES (?, 'awaiting_video_reference', ?)", [$internalId, json_encode($extra)]);
            $keyboard = [
                'inline_keyboard' => [
                    [['text' => '⏭️ نیازی ندارم', 'callback_data' => 'vid_skip_reference']],
                ]
            ];
            $this->baleClient->sendMessage($chatId, "🖼️ **تصویر مرجع ارسال کنید**\n\nیک تصویر مرجع برای ویدئو ارسال کنید.\nیا دکمه «نیازی ندارم» را بزنید.", $keyboard);
            return;
        }

        if ($nextStep === 'duration') {
            $extra['step'] = 'duration';
            $db->query("REPLACE INTO bot_state (user_id, state, extra_data) VALUES (?, 'awaiting_video_prompt', ?)", [$internalId, json_encode($extra)]);
            $this->showDurationSelection($chatId, $userId, $internalId, $extra);
            return;
        }

        if ($nextStep === 'aspect_ratio') {
            $extra['step'] = 'aspect_ratio';
            $db->query("REPLACE INTO bot_state (user_id, state, extra_data) VALUES (?, 'awaiting_video_prompt', ?)", [$internalId, json_encode($extra)]);
            $this->showAspectRatioSelection($chatId, $userId, $internalId, $extra);
            return;
        }

        if ($nextStep === 'resolution') {
            $extra['step'] = 'resolution';
            $db->query("REPLACE INTO bot_state (user_id, state, extra_data) VALUES (?, 'awaiting_video_prompt', ?)", [$internalId, json_encode($extra)]);
            $this->showResolutionSelection($chatId, $userId, $internalId, $extra);
            return;
        }

        if ($nextStep === 'prompt') {
            $extra['step'] = 'prompt';
            $db->query("REPLACE INTO bot_state (user_id, state, extra_data) VALUES (?, 'awaiting_video_prompt', ?)", [$internalId, json_encode($extra)]);
            $this->askPrompt($chatId, $userId, $internalId, $extra);
            return;
        }

        // Fallback: show confirm
        $extra['step'] = 'confirm';
        $db->query("REPLACE INTO bot_state (user_id, state, extra_data) VALUES (?, 'awaiting_video_prompt', ?)", [$internalId, json_encode($extra)]);
        $this->showConfirm($chatId, $userId, $internalId, $extra);
    }

    /**
     * Determine the next step based on model capabilities and current step.
     * Correct order: first_frame → last_frame → reference → duration → aspect_ratio → resolution → prompt → confirm
     */
    private function getNextStep(array $extra, string $currentStep): string
    {
        $allowFirstFrame = (int) ($extra['allow_first_frame'] ?? 0);
        $allowLastFrame = (int) ($extra['allow_last_frame'] ?? 0);
        $allowReference = (int) ($extra['allow_input_references'] ?? 0);
        $aspectRatios = trim($extra['supported_aspect_ratios'] ?? '');
        $resolutions = trim($extra['supported_resolutions'] ?? '');
        $durations = trim($extra['supported_durations'] ?? '');

        switch ($currentStep) {
            case 'started':
                // First: image steps
                if ($allowFirstFrame && empty($extra['first_frame_file_id'] ?? '')) return 'first_frame';
                if ($allowLastFrame && empty($extra['last_frame_file_id'] ?? '')) return 'last_frame';
                if ($allowReference && empty($extra['reference_file_id'] ?? '')) return 'reference';
                // Then: settings
                if (!empty($durations) && empty($extra['duration'] ?? '')) return 'duration';
                if (!empty($aspectRatios) && empty($extra['aspect_ratio'] ?? '')) return 'aspect_ratio';
                if (!empty($resolutions) && empty($extra['resolution'] ?? '')) return 'resolution';
                // Finally: prompt
                if (empty($extra['prompt'] ?? '')) return 'prompt';
                return 'confirm';

            case 'first_frame':
                if ($allowLastFrame && empty($extra['last_frame_file_id'] ?? '')) return 'last_frame';
                if ($allowReference && empty($extra['reference_file_id'] ?? '')) return 'reference';
                if (!empty($durations) && empty($extra['duration'] ?? '')) return 'duration';
                if (!empty($aspectRatios) && empty($extra['aspect_ratio'] ?? '')) return 'aspect_ratio';
                if (!empty($resolutions) && empty($extra['resolution'] ?? '')) return 'resolution';
                if (empty($extra['prompt'] ?? '')) return 'prompt';
                return 'confirm';

            case 'last_frame':
                if ($allowReference && empty($extra['reference_file_id'] ?? '')) return 'reference';
                if (!empty($durations) && empty($extra['duration'] ?? '')) return 'duration';
                if (!empty($aspectRatios) && empty($extra['aspect_ratio'] ?? '')) return 'aspect_ratio';
                if (!empty($resolutions) && empty($extra['resolution'] ?? '')) return 'resolution';
                if (empty($extra['prompt'] ?? '')) return 'prompt';
                return 'confirm';

            case 'reference':
                if (!empty($durations) && empty($extra['duration'] ?? '')) return 'duration';
                if (!empty($aspectRatios) && empty($extra['aspect_ratio'] ?? '')) return 'aspect_ratio';
                if (!empty($resolutions) && empty($extra['resolution'] ?? '')) return 'resolution';
                if (empty($extra['prompt'] ?? '')) return 'prompt';
                return 'confirm';

            case 'duration':
                if (!empty($aspectRatios) && empty($extra['aspect_ratio'] ?? '')) return 'aspect_ratio';
                if (!empty($resolutions) && empty($extra['resolution'] ?? '')) return 'resolution';
                if (empty($extra['prompt'] ?? '')) return 'prompt';
                return 'confirm';

            case 'aspect_ratio':
                if (!empty($resolutions) && empty($extra['resolution'] ?? '')) return 'resolution';
                if (empty($extra['prompt'] ?? '')) return 'prompt';
                return 'confirm';

            case 'resolution':
                if (empty($extra['prompt'] ?? '')) return 'prompt';
                return 'confirm';

            case 'prompt':
                if (empty($extra['prompt'] ?? '')) return 'prompt';
                return 'confirm';

            default:
                if (empty($extra['prompt'] ?? '')) return 'prompt';
                return 'confirm';
        }
    }

    /**
     * Ask for prompt text.
     */
    private function askPrompt(int $chatId, int $userId, int $internalId, array $extra): void
    {
        $displayName = $extra['video_model_name'] ?? '';
        $costPerSec = (int) ($extra['cost_per_second'] ?? 1);
        $duration = (int) ($extra['duration'] ?? 5);
        $totalCost = $duration * $costPerSec;

        $msg = "✏️ **لطفاً متن (پرامپت) ویدئوی خود را بنویسید**\n\n"
             . "🤖 مدل: {$displayName}\n"
             . "⏱️ مدت: {$duration} ثانیه\n"
             . "💰 هزینه تخمینی: {$totalCost} اعتبار\n\n"
             . "مثال:\n"
             . "• یک سگ طلایی در ساحل آفتابی در حال بازی با توپ\n"
             . "• غروب آفتاب بر فراز کوه‌های آلپ با دوربین آهسته\n\n"
             . "💡 هرچه توضیحات دقیق‌تر باشد، نتیجه بهتر است.\n"
             . "📝 /cancel برای لغو";

        $this->baleClient->sendMessage($chatId, $msg);
    }

    /**
     * Process the prompt text, then show confirm.
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
        $extra['prompt'] = $prompt;
        $extra['step'] = 'prompt';

        // Move to confirm
        $this->advanceToNextStep($chatId, $userId, $internalId, $extra);
    }

    /**
     * Process first frame photo.
     */
    private function processFirstFrame(int $chatId, int $userId, $update): void
    {
        $db = Database::getInstance();
        $internalId = $this->resolveUserId($userId);
        if (!$internalId) return;

        $fileId = $this->getPhotoFileId($update);
        if (!$fileId) {
            $this->baleClient->sendMessage($chatId, "⚠️ خطا در دریافت تصویر. مجدداً تلاش کنید.");
            return;
        }

        $stateData = $db->query("SELECT extra_data FROM bot_state WHERE user_id = ?", [$internalId])->fetch();
        $extra = json_decode($stateData['extra_data'] ?? '{}', true);
        $extra['first_frame_file_id'] = $fileId;
        $extra['step'] = 'first_frame';

        $this->advanceToNextStep($chatId, $userId, $internalId, $extra);
    }

    /**
     * Process last frame photo.
     */
    private function processLastFrame(int $chatId, int $userId, $update): void
    {
        $db = Database::getInstance();
        $internalId = $this->resolveUserId($userId);
        if (!$internalId) return;

        $fileId = $this->getPhotoFileId($update);
        if (!$fileId) {
            $this->baleClient->sendMessage($chatId, "⚠️ خطا در دریافت تصویر. مجدداً تلاش کنید.");
            return;
        }

        $stateData = $db->query("SELECT extra_data FROM bot_state WHERE user_id = ?", [$internalId])->fetch();
        $extra = json_decode($stateData['extra_data'] ?? '{}', true);
        $extra['last_frame_file_id'] = $fileId;
        $extra['step'] = 'last_frame';

        $this->advanceToNextStep($chatId, $userId, $internalId, $extra);
    }

    /**
     * Process reference photo.
     */
    private function processReference(int $chatId, int $userId, $update): void
    {
        $db = Database::getInstance();
        $internalId = $this->resolveUserId($userId);
        if (!$internalId) return;

        $fileId = $this->getPhotoFileId($update);
        if (!$fileId) {
            $this->baleClient->sendMessage($chatId, "⚠️ خطا در دریافت تصویر. مجدداً تلاش کنید.");
            return;
        }

        $stateData = $db->query("SELECT extra_data FROM bot_state WHERE user_id = ?", [$internalId])->fetch();
        $extra = json_decode($stateData['extra_data'] ?? '{}', true);
        $extra['reference_file_id'] = $fileId;
        $extra['step'] = 'reference';

        $this->advanceToNextStep($chatId, $userId, $internalId, $extra);
    }

    /**
     * Handle skip callback: vid_skip_{step}
     */
    private function handleSkipCallback(int $chatId, int $userId, string $callbackData): void
    {
        $db = Database::getInstance();
        $internalId = $this->resolveUserId($userId);
        if (!$internalId) return;

        $stateData = $db->query("SELECT extra_data FROM bot_state WHERE user_id = ?", [$internalId])->fetch();
        $extra = json_decode($stateData['extra_data'] ?? '{}', true);

        $this->advanceToNextStep($chatId, $userId, $internalId, $extra);
    }

    /**
     * Show duration selection as inline buttons.
     */
    private function showDurationSelection(int $chatId, int $userId, int $internalId, array $extra): void
    {
        $durations = $this->parseCsv($extra['supported_durations'] ?? '');
        if (empty($durations)) {
            $durations = ['5', '10', '15'];
        }

        $costPerSec = (int) ($extra['cost_per_second'] ?? 1);
        $msg = "⏱️ **مدت زمان ویدئو را انتخاب کنید**\n\n"
             . "💰 هزینه: {$costPerSec} اعتبار/ثانیه\n\n"
             . "مدت مورد نظر را انتخاب کنید:\n";

        $keyboard = ['inline_keyboard' => []];
        $durationsInt = array_map('intval', $durations);
        sort($durationsInt);
        $chunks = array_chunk($durationsInt, 5);
        foreach ($chunks as $chunk) {
            $row = [];
            foreach ($chunk as $d) {
                $totalCost = $d * $costPerSec;
                $row[] = ['text' => $d . 's (' . $totalCost . ' اعتبار)', 'callback_data' => 'vid_dur_' . $d];
            }
            $keyboard['inline_keyboard'][] = $row;
        }
        $keyboard['inline_keyboard'][] = [['text' => '🔙 بازگشت به مدل‌ها', 'callback_data' => 'vid_back_model']];

        $this->baleClient->sendMessage($chatId, $msg, $keyboard);
    }

    /**
     * Handle duration callback: vid_dur_{duration}
     */
    private function handleDurationCallback(int $chatId, int $userId, string $callbackData): void
    {
        $rest = substr($callbackData, 8); // remove "vid_dur_"
        $duration = (int) $rest;

        $db = Database::getInstance();
        $internalId = $this->resolveUserId($userId);
        if (!$internalId) return;

        $stateData = $db->query("SELECT extra_data FROM bot_state WHERE user_id = ?", [$internalId])->fetch();
        $extra = json_decode($stateData['extra_data'] ?? '{}', true);
        $extra['duration'] = $duration;
        $extra['step'] = 'duration';

        $this->advanceToNextStep($chatId, $userId, $internalId, $extra);
    }

    /**
     * Show aspect ratio selection as inline buttons.
     */
    private function showAspectRatioSelection(int $chatId, int $userId, int $internalId, array $extra): void
    {
        $aspectRatios = $this->parseCsv($extra['supported_aspect_ratios'] ?? '');
        if (empty($aspectRatios)) {
            $aspectRatios = ['16:9', '9:16', '1:1'];
        }

        $msg = "📐 **نسبت تصویر را انتخاب کنید**\n\n";
        $keyboard = ['inline_keyboard' => []];
        $row = [];
        foreach ($aspectRatios as $ar) {
            $row[] = ['text' => $ar, 'callback_data' => 'vid_ar_' . $ar];
        }
        $keyboard['inline_keyboard'] = array_chunk($row, 3);
        $keyboard['inline_keyboard'][] = [['text' => '🔙 بازگشت به مدل‌ها', 'callback_data' => 'vid_back_model']];

        $this->baleClient->sendMessage($chatId, $msg, $keyboard);
    }

    /**
     * Handle aspect ratio callback: vid_ar_{aspectRatio}
     */
    private function handleAspectRatioCallback(int $chatId, int $userId, string $callbackData): void
    {
        $rest = substr($callbackData, 7); // remove "vid_ar_"
        $aspectRatio = $rest;

        $db = Database::getInstance();
        $internalId = $this->resolveUserId($userId);
        if (!$internalId) return;

        $stateData = $db->query("SELECT extra_data FROM bot_state WHERE user_id = ?", [$internalId])->fetch();
        $extra = json_decode($stateData['extra_data'] ?? '{}', true);
        $extra['aspect_ratio'] = $aspectRatio;
        $extra['step'] = 'aspect_ratio';

        $this->advanceToNextStep($chatId, $userId, $internalId, $extra);
    }

    /**
     * Show resolution selection as inline buttons.
     */
    private function showResolutionSelection(int $chatId, int $userId, int $internalId, array $extra): void
    {
        $resolutions = $this->parseCsv($extra['supported_resolutions'] ?? '');
        if (empty($resolutions)) {
            $resolutions = ['480p', '720p', '1080p'];
        }

        $msg = "📐 **resolution را انتخاب کنید**\n\n";
        $keyboard = ['inline_keyboard' => []];
        $row = [];
        foreach ($resolutions as $r) {
            $row[] = ['text' => $r, 'callback_data' => 'vid_res_' . $r];
        }
        $keyboard['inline_keyboard'] = array_chunk($row, 3);
        $keyboard['inline_keyboard'][] = [['text' => '🔙 بازگشت به مدل‌ها', 'callback_data' => 'vid_back_model']];

        $this->baleClient->sendMessage($chatId, $msg, $keyboard);
    }

    /**
     * Handle resolution callback: vid_res_{resolution}
     */
    private function handleResolutionCallback(int $chatId, int $userId, string $callbackData): void
    {
        $rest = substr($callbackData, 8); // remove "vid_res_"
        $resolution = $rest;

        $db = Database::getInstance();
        $internalId = $this->resolveUserId($userId);
        if (!$internalId) return;

        $stateData = $db->query("SELECT extra_data FROM bot_state WHERE user_id = ?", [$internalId])->fetch();
        $extra = json_decode($stateData['extra_data'] ?? '{}', true);
        $extra['resolution'] = $resolution;
        $extra['step'] = 'resolution';

        $this->advanceToNextStep($chatId, $userId, $internalId, $extra);
    }

    /**
     * Show final confirmation with cost breakdown and prompt.
     */
    private function showConfirm(int $chatId, int $userId, int $internalId, array $extra): void
    {
        $db = Database::getInstance();
        $modelId = (int) ($extra['video_model_id'] ?? 0);
        $model = $db->query("SELECT id, name, display_name FROM ai_video_models WHERE id = ?", [$modelId])->fetch();
        if (!$model) return;

        $displayName = $model['display_name'] ?? $model['name'];
        $prompt = $extra['prompt'] ?? '';
        $duration = (int) ($extra['duration'] ?? 5);
        $costPerSec = (int) ($extra['cost_per_second'] ?? 1);
        $totalCost = $duration * $costPerSec;
        $resolution = $extra['resolution'] ?? '';
        $aspectRatio = $extra['aspect_ratio'] ?? '';
        $hasFirstFrame = !empty($extra['first_frame_file_id'] ?? '');
        $hasLastFrame = !empty($extra['last_frame_file_id'] ?? '');
        $hasReference = !empty($extra['reference_file_id'] ?? '');

        $msg = "🎬 **تایید نهایی**\n\n"
             . "📝 پرامپت: {$prompt}\n"
             . "🤖 مدل: {$displayName}\n"
             . "⏱️ مدت: {$duration} ثانیه\n"
             . "💰 هزینه: {$costPerSec} اعتبار/ثانیه = **{$totalCost} اعتبار**\n";
        if ($resolution) $msg .= "📐 resolution: {$resolution}\n";
        if ($aspectRatio) $msg .= "📐 نسبت تصویر: {$aspectRatio}\n";
        if ($hasFirstFrame) $msg .= "🖼️ فریم اول: ✅\n";
        if ($hasLastFrame) $msg .= "🖼️ فریم آخر: ✅\n";
        if ($hasReference) $msg .= "🖼️ تصویر مرجع: ✅\n";
        $msg .= "\n✅ برای ارسال، دکمه زیر را بزنید.";

        $keyboard = [
            'inline_keyboard' => [
                [['text' => '✅ ارسال به هوش مصنوعی', 'callback_data' => 'vid_confirm_' . $modelId]],
                [['text' => '🔙 بازگشت به مدل‌ها', 'callback_data' => 'vid_back_model']],
            ]
        ];

        $this->baleClient->sendMessage($chatId, $msg, $keyboard);
    }

    /**
     * Handle confirm callback: vid_confirm_{modelId}
     */
    private function handleConfirmCallback(int $chatId, int $userId, string $callbackData): void
    {
        $rest = substr($callbackData, 13); // remove "vid_confirm_"
        $modelId = (int) $rest;

        $db = Database::getInstance();
        $internalId = $this->resolveUserId($userId);
        if (!$internalId) return;

        $model = $db->query("SELECT * FROM ai_video_models WHERE id = ?", [$modelId])->fetch();
        if (!$model) {
            $this->baleClient->sendMessage($chatId, "❌ مدل یافت نشد.");
            return;
        }

        // Get state data
        $stateData = $db->query("SELECT extra_data FROM bot_state WHERE user_id = ?", [$internalId])->fetch();
        $extra = json_decode($stateData['extra_data'] ?? '{}', true);
        $prompt = $extra['prompt'] ?? '';
        $duration = (int) ($extra['duration'] ?? 5);
        $costPerSec = (int) ($extra['cost_per_second'] ?? 1);
        $totalCost = $duration * $costPerSec;

        if (empty($prompt)) {
            $this->baleClient->sendMessage($chatId, "⚠️ پرامپت یافت نشد. دوباره تلاش کنید.");
            return;
        }

        // Check credit
        if (!CreditService::hasEnoughCredit($internalId, $totalCost)) {
            $buyCreditKeyboard = [
                'inline_keyboard' => [
                    [['text' => "\xF0\x9F\x92\xB3 برای افزایش اعتبار کلیک کن", 'callback_data' => 'buy_credit']],
                ]
            ];
            $this->baleClient->sendMessage($chatId, "❌ **اعتبار کافی نیست!**\n\nشما به {$totalCost} اعتبار نیاز دارید.\nلطفاً از بخش «💳 خرید اعتبار» حساب خود را شارژ کنید.", $buyCreditKeyboard);
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
        if (!empty($extra['resolution'] ?? '')) $params['resolution'] = $extra['resolution'];
        if (!empty($extra['aspect_ratio'] ?? '')) $params['aspect_ratio'] = $extra['aspect_ratio'];
        if (!empty($extra['first_frame_file_id'] ?? '')) $params['first_frame_file_id'] = $extra['first_frame_file_id'];
        if (!empty($extra['last_frame_file_id'] ?? '')) $params['last_frame_file_id'] = $extra['last_frame_file_id'];
        if (!empty($extra['reference_file_id'] ?? '')) $params['reference_file_id'] = $extra['reference_file_id'];

        // Submit to VideoService
        $videoService = new VideoService();
        $result = $videoService->submit($params);

        if (isset($result['error'])) {
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

        // Start polling
        $this->pollVideoJob($chatId, $internalId, $pollingUrl, $totalCost, $model['name'], $prompt);
    }

    /**
     * Poll the video job and send result back to user.
     */
    private function pollVideoJob(int $chatId, int $internalId, string $pollingUrl, int $cost, string $modelName, string $prompt): void
    {
        $videoService = new VideoService();
        $maxAttempts = 20;
        $attempt = 0;

        while ($attempt < $maxAttempts) {
            $attempt++;
            sleep(5);

            $result = $videoService->poll($pollingUrl);

            if (isset($result['error'])) {
                $this->baleClient->sendMessage($chatId, "❌ خطا در بررسی وضعیت: " . $result['error']);
                Logger::error('VideoHandler::poll error', ['user_id' => $internalId, 'error' => $result['error']]);
                return;
            }

            if ($result['status'] === 'completed') {
                $urls = $result['unsigned_urls'] ?? [];
                if (empty($urls)) {
                    $this->baleClient->sendMessage($chatId, "⚠️ ویدئو ساخته شد اما لینک دانلودی یافت نشد.");
                    return;
                }

                // Deduct credit
                $refId = 'video_' . $internalId . '_' . time();
                CreditService::deduct($internalId, $cost, $refId);

                $sentCount = count($urls);
                $downloadMsg = "🔗 **لینک‌های دانلود ویدئو:**\n\n";
                foreach ($urls as $i => $url) {
                    $num = $i + 1;
                    $downloadMsg .= "{$num}. [دانلود ویدئو {$num}]({$url})\n";
                }
                $this->baleClient->sendMessage($chatId, $downloadMsg);

                $msg = "✅ **ویدئو ساخته شد!**\n\n"
                     . "🤖 مدل: {$modelName}\n"
                     . "💰 هزینه کسر شده: {$cost} اعتبار\n"
                     . "🔖 reference: {$refId}";
                $this->baleClient->sendMessage($chatId, $msg);

                Logger::info('VideoHandler::completed', [
                    'user_id' => $internalId,
                    'model' => $modelName,
                    'cost' => $cost,
                    'ref_id' => $refId,
                    'sent_count' => $sentCount,
                ]);

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

            if ($attempt % 3 === 0) {
                $this->baleClient->sendMessage($chatId, "⏳ در حال ساخت ویدئو... (مدت انتظار: " . ($attempt * 5) . " ثانیه)");
            }
        }

        $this->baleClient->sendMessage($chatId, "⏰ زمان انتظار به پایان رسید. ویدئو هنوز آماده نشده است.\n"
            . "لطفاً بعداً با پشتیبانی تماس بگیرید.\n"
            . "🆔 Job ID: " . basename($pollingUrl));
        Logger::error('VideoHandler::timeout', [
            'user_id' => $internalId,
            'polling_url' => $pollingUrl,
        ]);
    }

    /**
     * Get the largest photo file_id from an update.
     */
    private function getPhotoFileId($update): ?string
    {
        try {
            // Use Update class's built-in method
            return $update->getPhotoFileId();
        } catch (\Throwable $e) {
            error_log("VideoHandler::getPhotoFileId ERROR: " . $e->getMessage());
            return null;
        }
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
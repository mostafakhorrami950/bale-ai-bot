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
     * Fixes: frame_images format per OpenRouter docs, logging to log file.
     */
    private function handleConfirmCallback(int $chatId, int $userId, string $callbackData): void
    {
        $rest = substr($callbackData, 13); // remove "vid_confirm_"
        $modelIdFromCallback = (int) $rest;

        $db = Database::getInstance();
        $internalId = $this->resolveUserId($userId);
        if (!$internalId) return;

        // Get state data FIRST — it has the correct model_id
        $stateData = $db->query("SELECT extra_data FROM bot_state WHERE user_id = ?", [$internalId])->fetch();
        if (!$stateData) {
            $this->baleClient->sendMessage($chatId, "⚠️ اطلاعات جلسه یافت نشد. دوباره از منو انتخاب کنید.");
            return;
        }
        $extra = json_decode($stateData['extra_data'] ?? '{}', true);

        // Use model_id from state (more reliable than callback parsing)
        $modelId = (int) ($extra['video_model_id'] ?? $modelIdFromCallback);
        $this->aiLog('INFO', 'handleConfirmCallback', [
            'modelIdFromCallback' => $modelIdFromCallback,
            'modelIdFromState' => $extra['video_model_id'] ?? 'none',
            'resolved' => $modelId,
        ]);

        $model = $db->query("SELECT * FROM ai_video_models WHERE id = ?", [$modelId])->fetch();
        if (!$model) {
            $this->aiLog('ERROR', 'Model not found', ['model_id' => $modelId]);
            $this->baleClient->sendMessage($chatId, "❌ مدل یافت نشد. لطفاً دوباره از منو انتخاب کنید.");
            return;
        }

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

        // Build payload per OpenRouter docs
        $payload = [
            'model' => $model['name'],
            'prompt' => $prompt,
        ];
        if (!empty($duration)) $payload['duration'] = $duration;
        if (!empty($extra['resolution'] ?? '')) $payload['resolution'] = $extra['resolution'];
        if (!empty($extra['aspect_ratio'] ?? '')) $payload['aspect_ratio'] = $extra['aspect_ratio'];

        // Handle frame_images per OpenRouter docs: download from Bale, convert to base64 data URI
        $frameImages = [];
        if (!empty($extra['first_frame_file_id'] ?? '')) {
            $base64 = $this->downloadBalePhotoAsBase64($extra['first_frame_file_id']);
            if ($base64) {
                $frameImages[] = [
                    'type' => 'image_url',
                    'image_url' => ['url' => 'data:image/jpeg;base64,' . $base64],
                    'frame_type' => 'first_frame',
                ];
                $this->aiLog('INFO', 'First frame downloaded and converted to base64', ['file_id' => $extra['first_frame_file_id']]);
            } else {
                $this->aiLog('WARN', 'Failed to download first frame from Bale', ['file_id' => $extra['first_frame_file_id']]);
            }
        }
        if (!empty($extra['last_frame_file_id'] ?? '')) {
            $base64 = $this->downloadBalePhotoAsBase64($extra['last_frame_file_id']);
            if ($base64) {
                $frameImages[] = [
                    'type' => 'image_url',
                    'image_url' => ['url' => 'data:image/jpeg;base64,' . $base64],
                    'frame_type' => 'last_frame',
                ];
                $this->aiLog('INFO', 'Last frame downloaded and converted to base64', ['file_id' => $extra['last_frame_file_id']]);
            } else {
                $this->aiLog('WARN', 'Failed to download last frame from Bale', ['file_id' => $extra['last_frame_file_id']]);
            }
        }
        if (!empty($frameImages)) {
            $payload['frame_images'] = $frameImages;
        }

        // Handle input_references per OpenRouter docs
        if (!empty($extra['reference_file_id'] ?? '')) {
            $base64 = $this->downloadBalePhotoAsBase64($extra['reference_file_id']);
            if ($base64) {
                $payload['input_references'] = [
                    [
                        'type' => 'image_url',
                        'image_url' => ['url' => 'data:image/jpeg;base64,' . $base64],
                    ]
                ];
                $this->aiLog('INFO', 'Reference image downloaded and converted to base64', ['file_id' => $extra['reference_file_id']]);
            } else {
                $this->aiLog('WARN', 'Failed to download reference image from Bale', ['file_id' => $extra['reference_file_id']]);
            }
        }

        $this->aiLog('INFO', 'Submitting video job', [
            'model' => $model['name'],
            'payload_keys' => array_keys($payload),
            'has_frame_images' => !empty($frameImages),
        ]);

        // Submit to VideoService
        $videoService = new VideoService();
        $result = $videoService->submit($payload);

        if (isset($result['error'])) {
            $db->query("DELETE FROM bot_state WHERE user_id = ?", [$internalId]);
            $this->baleClient->sendMessage($chatId, "❌ خطا: " . $result['error']);
            $this->aiLog('ERROR', 'Submit failed', ['error' => $result['error']]);
            return;
        }

        $jobId = $result['job_id'];
        $pollingUrl = $result['polling_url'];

        $this->aiLog('INFO', 'Job submitted', ['job_id' => $jobId, 'polling_url' => $pollingUrl]);

        // Save job info in state
        $extra['job_id'] = $jobId;
        $extra['polling_url'] = $pollingUrl;
        $extra['status_message_id'] = 0; // will be set on first status update
        $db->query("REPLACE INTO bot_state (user_id, state, extra_data) VALUES (?, 'vid_polling', ?)", [$internalId, json_encode($extra)]);

        // Send initial status
        $statusMsgId = $this->baleClient->sendMessage($chatId, "✅ درخواست شما ثبت شد.\n🆔 شناسه: {$jobId}\n⏳ در حال ساخت ویدئو... این فرآیند ممکن است چند دقیقه طول بکشد.");
        if ($statusMsgId) {
            $extra['status_message_id'] = $statusMsgId;
            $db->query("REPLACE INTO bot_state (user_id, state, extra_data) VALUES (?, 'vid_polling', ?)", [$internalId, json_encode($extra)]);
        }

        // Start polling
        $this->pollVideoJob($chatId, $internalId, $pollingUrl, $totalCost, $model['name'], $prompt);
    }

    /**
     * Download a photo from Bale by file_id and return as base64 string.
     */
    private function downloadBalePhotoAsBase64(string $fileId): ?string
    {
        try {
            $fileInfo = $this->baleClient->getFile($fileId);
            if (!$fileInfo || empty($fileInfo['file_path'])) {
                $this->aiLog('ERROR', 'getFile failed', ['file_id' => $fileId]);
                return null;
            }
            $binary = $this->baleClient->downloadFile($fileInfo['file_path']);
            if ($binary === null) {
                $this->aiLog('ERROR', 'downloadFile failed', ['file_path' => $fileInfo['file_path']]);
                return null;
            }
            return base64_encode($binary);
        } catch (\Throwable $e) {
            $this->aiLog('ERROR', 'downloadBalePhotoAsBase64 exception', ['error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * Poll the video job and send result back to user.
     * Fixes: delete previous status messages, download video and send as file, logging.
     */
    private function pollVideoJob(int $chatId, int $internalId, string $pollingUrl, int $cost, string $modelName, string $prompt): void
    {
        $videoService = new VideoService();
        $maxAttempts = 60; // 60 * 5s = 5 minutes max
        $attempt = 0;
        $lastStatusMsgId = 0;

        // Get current status_message_id from state
        $db = Database::getInstance();
        $stateData = $db->query("SELECT extra_data FROM bot_state WHERE user_id = ?", [$internalId])->fetch();
        if ($stateData) {
            $extra = json_decode($stateData['extra_data'] ?? '{}', true);
            $lastStatusMsgId = (int) ($extra['status_message_id'] ?? 0);
        }

        while ($attempt < $maxAttempts) {
            $attempt++;
            sleep(5);

            $result = $videoService->poll($pollingUrl);

            if (isset($result['error'])) {
                $this->baleClient->sendMessage($chatId, "❌ خطا در بررسی وضعیت: " . $result['error']);
                $this->aiLog('ERROR', 'Poll error', ['user_id' => $internalId, 'error' => $result['error']]);
                $db->query("DELETE FROM bot_state WHERE user_id = ?", [$internalId]);
                return;
            }

            if ($result['status'] === 'completed') {
                $urls = $result['unsigned_urls'] ?? [];
                if (empty($urls)) {
                    $this->baleClient->sendMessage($chatId, "⚠️ ویدئو ساخته شد اما لینک دانلودی یافت نشد.");
                    $db->query("DELETE FROM bot_state WHERE user_id = ?", [$internalId]);
                    return;
                }

                $this->aiLog('INFO', 'Video completed, downloading...', [
                    'user_id' => $internalId,
                    'url_count' => count($urls),
                    'urls' => $urls,
                ]);

                // Download each video and send as file
                $sentCount = 0;
                $generationId = $result['generation_id'] ?? ('video_' . $internalId . '_' . time());
                foreach ($urls as $index => $contentUrl) {
                    $this->aiLog('INFO', 'Downloading video', ['index' => $index, 'url' => $contentUrl]);
                    $videoData = $videoService->download($contentUrl);
                    if ($videoData === null) {
                        $this->aiLog('ERROR', 'Download failed', ['index' => $index, 'url' => $contentUrl]);
                        $this->baleClient->sendMessage($chatId, "⚠️ خطا در دانلود ویدئو شماره " . ($index + 1));
                        continue;
                    }

                    // Save to temp file
                    $ext = '.mp4';
                    $tempFile = $this->tempDir . 'video_' . $internalId . '_' . time() . '_' . $index . $ext;
                    file_put_contents($tempFile, $videoData);

                    $this->aiLog('INFO', 'Video saved to temp file', ['path' => $tempFile, 'size' => strlen($videoData)]);

                    // Save to permanent storage + insert into generated_files
                    $savedPath = $this->saveGeneratedFile($internalId, $generationId, $modelName, $prompt, 'video', 'text2video', $videoData, $ext, $tempFile);

                    // Send as DOCUMENT (file), not as video content
                    $caption = ($index === 0) ? "🎬 **ویدئو ساخته شد!**\n🤖 مدل: {$modelName}\n🆔 Gen: {$generationId}" : '';
                    $sendPath = $savedPath ?: $tempFile;
                    $success = $this->baleClient->sendDocument($chatId, $sendPath, $caption);
                    if ($success) {
                        $sentCount++;
                        $this->aiLog('INFO', 'Video sent to user', ['index' => $index, 'file' => $sendPath]);
                    } else {
                        $this->aiLog('ERROR', 'Failed to send video to user', ['index' => $index, 'error' => $this->baleClient->getLastError()]);
                        $this->baleClient->sendMessage($chatId, "⚠️ خطا در ارسال ویدئو. لینک مستقیم:\n{$contentUrl}");
                    }

                    // Clean up temp file (permanent storage file stays)
                    @unlink($tempFile);
                }

                if ($sentCount > 0) {
                    // Deduct credit
                    $refId = 'video_' . $internalId . '_' . time();
                    CreditService::deduct($internalId, $cost, $refId);

                    $keyboard = [
                        'inline_keyboard' => [
                            [['text' => "🔍 پیگیری ساخت تصویر و ویدئو", 'callback_data' => 'track_generation']],
                        ]
                    ];
                    $msg = "✅ **{$sentCount} ویدئو ارسال شد!**\n"
                         . "💰 هزینه کسر شده: {$cost} اعتبار\n"
                         . "🆔 **Generation ID:** `{$generationId}`\n\n"
                         . "📌 اگر ویدئو را دریافت نکرده‌اید، روی دکمه زیر کلیک کنید و **Generation ID** را ارسال کنید.";
                    $this->baleClient->sendMessage($chatId, $msg, $keyboard);

                    $this->aiLog('INFO', 'Video generation completed', [
                        'user_id' => $internalId,
                        'model' => $modelName,
                        'cost' => $cost,
                        'ref_id' => $refId,
                        'generation_id' => $generationId,
                        'sent_count' => $sentCount,
                    ]);
                }

                $db->query("DELETE FROM bot_state WHERE user_id = ?", [$internalId]);
                return;
            }

            if ($result['status'] === 'failed') {
                $errorMsg = $result['error'] ?? 'خطای نامشخص';
                $this->baleClient->sendMessage($chatId, "❌ **ساخت ویدئو ناموفق بود.**\n\n{$errorMsg}");
                $this->aiLog('ERROR', 'Video generation failed', [
                    'user_id' => $internalId,
                    'error' => $errorMsg,
                ]);
                $db->query("DELETE FROM bot_state WHERE user_id = ?", [$internalId]);
                return;
            }

            // Send/update status message every 3 attempts (15 seconds)
            if ($attempt % 3 === 0) {
                $statusText = "⏳ در حال ساخت ویدئو... (مدت انتظار: " . ($attempt * 5) . " ثانیه)";
                
                // Delete previous status message if exists
                if ($lastStatusMsgId > 0) {
                    $this->baleClient->deleteMessage($chatId, $lastStatusMsgId);
                }
                
                // Send new status message
                $newMsgId = $this->baleClient->sendMessage($chatId, $statusText);
                if ($newMsgId) {
                    $lastStatusMsgId = $newMsgId;
                    // Update state with new message_id
                    $stateData = $db->query("SELECT extra_data FROM bot_state WHERE user_id = ?", [$internalId])->fetch();
                    if ($stateData) {
                        $extra = json_decode($stateData['extra_data'] ?? '{}', true);
                        $extra['status_message_id'] = $lastStatusMsgId;
                        $db->query("REPLACE INTO bot_state (user_id, state, extra_data) VALUES (?, 'vid_polling', ?)", [$internalId, json_encode($extra)]);
                    }
                }
            }
        }

        // Timeout
        $this->baleClient->sendMessage($chatId, "⏰ زمان انتظار به پایان رسید. ویدئو هنوز آماده نشده است.\n"
            . "لطفاً بعداً با پشتیبانی تماس بگیرید.\n"
            . "🆔 Job ID: " . basename($pollingUrl));
        $this->aiLog('ERROR', 'Poll timeout', [
            'user_id' => $internalId,
            'polling_url' => $pollingUrl,
        ]);
        $db->query("DELETE FROM bot_state WHERE user_id = ?", [$internalId]);
    }

    /**
     * Log to the AI log file.
     */
    private function aiLog(string $level, string $message, array $context = []): void
    {
        $logFile = Config::get('AI_LOG_FILE', Config::get('BASE_PATH', __DIR__ . '/../../..') . '/logs_ai.txt');
        $timestamp = date('Y-m-d H:i:s');
        $contextStr = !empty($context) ? ' ' . json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : '';
        $line = "[{$timestamp}] [{$level}] [VideoHandler] {$message}{$contextStr}\n";
        @file_put_contents($logFile, $line, FILE_APPEND | LOCK_EX);
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

    /**
     * Save a generated file locally and insert a record into generated_files table.
     */
    private function saveGeneratedFile(int $internalId, string $generationId, string $modelName, string $prompt, string $fileType, string $mediaType, string $fileData, string $ext, string $sourceFile): ?string
    {
        try {
            $storageDir = Config::get('BASE_PATH', __DIR__ . '/../../..') . '/uploads/ai_generated/';
            if (!is_dir($storageDir)) @mkdir($storageDir, 0755, true);
            
            $filename = 'vid_' . $internalId . '_' . time() . '_' . bin2hex(random_bytes(4)) . $ext;
            $filePath = $storageDir . $filename;
            
            // Copy from source file or write data
            if (file_exists($sourceFile)) {
                copy($sourceFile, $filePath);
            } else {
                file_put_contents($filePath, $fileData);
            }
            
            if (!file_exists($filePath)) {
                $this->aiLog('ERROR', 'saveGeneratedFile: failed to create file', ['path' => $filePath]);
                return null;
            }
            
            $fileSize = filesize($filePath);
            $mime = 'video/mp4';
            
            $db = Database::getInstance();
            $db->query("INSERT INTO generated_files (user_id, generation_id, model_name, prompt, file_type, media_type, file_path, file_size, mime_type, stored_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())",
                [$internalId, $generationId, $modelName, $prompt, $fileType, $mediaType, $filePath, $fileSize, $mime]
            );
            
            $this->aiLog('INFO', 'saveGeneratedFile: file saved', ['path' => $filePath, 'size' => $fileSize]);
            return $filePath;
        } catch (\Throwable $e) {
            $this->aiLog('ERROR', 'saveGeneratedFile exception', ['error' => $e->getMessage()]);
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
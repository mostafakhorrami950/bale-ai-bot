<?php

namespace Admin;

use Core\AILogger;

/**
 * Shared helper for admin model management pages.
 * Handles validation, logging, and form rendering logic.
 * Pure static methods — no state.
 */
class ModelHelper
{
    /**
     * Whitelist of allowed model types.
     */
    public static function allowedTypes(): array
    {
        return ['text', 'image_generation', 'image_editing', 'video'];
    }

    /**
     * Whitelist of allowed providers.
     */
    public static function allowedProviders(): array
    {
        return ['openrouter', 'custom'];
    }

    /**
     * Whitelist of allowed aspect ratios.
     */
    public static function allowedAspectRatios(): array
    {
        return ['auto', '1:1', '2:3', '3:2', '3:4', '4:3', '4:5', '5:4', '9:16', '16:9', '21:9'];
    }

    /**
     * Whitelist of allowed image sizes.
     */
    public static function allowedSizes(): array
    {
        return ['auto', '0.5K', '1K', '2K', '4K'];
    }

    /**
     * Validate and sanitize common model input from $_POST.
     * Returns sanitized data array on success, throws on failure.
     */
    public static function validateAndSanitize(array $post): array
    {
        // Model type
        $modelType = trim($post['model_type'] ?? '');
        if (!in_array($modelType, self::allowedTypes(), true)) {
            throw new \InvalidArgumentException('نوع مدل نامعتبر است');
        }

        // Name (internal)
        $name = trim($post['name'] ?? '');
        if ($name === '') {
            throw new \InvalidArgumentException('نام مدل الزامی است');
        }
        if (mb_strlen($name) > 200) {
            throw new \InvalidArgumentException('نام مدل حداکثر ۲۰۰ کاراکتر مجاز است');
        }

        // Display name (shown in bot buttons)
        $displayName = trim($post['display_name'] ?? '');
        if ($displayName === '') {
            $displayName = $name; // fallback
        }
        if (mb_strlen($displayName) > 200) {
            throw new \InvalidArgumentException('نام نمایشی حداکثر ۲۰۰ کاراکتر مجاز است');
        }

        // Description
        $description = trim($post['description'] ?? '');

        // Provider
        $provider = trim($post['provider'] ?? 'openrouter');
        if (!in_array($provider, self::allowedProviders(), true)) {
            throw new \InvalidArgumentException('ارائه‌دهنده نامعتبر است');
        }

        // Active
        $isActive = isset($post['is_active']) ? 1 : 0;

        // Text-specific: per-character costs, no cost_per_image
        $costPerInputChar = 0.000001;
        $costPerOutputChar = 0.000002;
        $freeModel = 0;
        $costPerImage = 0;

        if ($modelType === 'text') {
            $costPerInputChar = (float)($post['cost_per_input_char'] ?? 0.000001);
            $costPerOutputChar = (float)($post['cost_per_output_char'] ?? 0.000002);
            $freeModel = isset($post['free_model']) ? 1 : 0;
        } else {
            // Image & video: cost_per_image is required, must be positive integer
            $rawCost = $post['cost_per_image'] ?? '';
            if ($rawCost === '' || !ctype_digit(ltrim((string)$rawCost, '-')) || (int)$rawCost < 1) {
                throw new \InvalidArgumentException('هزینه باید یک عدد صحیح مثبت باشد');
            }
            $costPerImage = (int)$rawCost;

            // Image-specific size / aspect_ratio (text2img & img2img)
            if (in_array($modelType, ['image_generation', 'image_editing'], true)) {
                $postSize = trim($post['size'] ?? 'auto');
                if (!in_array($postSize, self::allowedSizes(), true)) {
                    throw new \InvalidArgumentException('سایز تصویر نامعتبر است');
                }
                $post['size'] = $postSize;

                $postAr = trim($post['aspect_ratio'] ?? 'auto');
                if (!in_array($postAr, self::allowedAspectRatios(), true)) {
                    throw new \InvalidArgumentException('نسبت تصویر نامعتبر است');
                }
                $post['aspect_ratio'] = $postAr;
            }
        }

        $result = [
            'name'                 => $name,
            'display_name'         => $displayName,
            'description'          => $description,
            'provider'             => $provider,
            'model_type'           => $modelType,
            'cost_per_image'       => $costPerImage,
            'is_active'            => $isActive,
            'cost_per_input_char'  => $costPerInputChar,
            'cost_per_output_char' => $costPerOutputChar,
            'free_model'           => $freeModel,
            'size'                 => $post['size'] ?? 'auto',
            'aspect_ratio'         => $post['aspect_ratio'] ?? 'auto',
            'model_config'         => '{}',
        ];

        return $result;
    }

    /**
     * Log a model management action with context.
     */
    public static function logAction(string $action, array $context = []): void
    {
        AILogger::log('ADMIN_MODEL_' . strtoupper($action), $context);
    }

    /**
     * Human-readable label for a model type.
     */
    public static function typeLabel(string $type): string
    {
        $labels = [
            'text'              => '📝 متنی',
            'image_generation'  => '🎨 تصویرساز',
            'image_editing'     => '🖼 ویرایش',
            'video'             => '🎬 ویدئو',
        ];
        return $labels[$type] ?? '🎨 تصویرساز';
    }

    /**
     * Human-readable label for a size option.
     */
    public static function sizeLabel(string $size): string
    {
        $labels = [
            'auto' => 'auto (پیشفرض)',
            '0.5K' => '0.5K (کمترین رزولوشن)',
            '1K'   => '1K (استاندارد)',
            '2K'   => '2K (بالا)',
            '4K'   => '4K (بیشترین)',
        ];
        return $labels[$size] ?? $size;
    }

    /**
     * Human-readable label for aspect ratio.
     */
    public static function aspectRatioLabel(string $ratio): string
    {
        if ($ratio === 'auto') return 'auto (پیشفرض)';
        return $ratio;
    }

    /**
     * Render success/error alert HTML.
     */
    public static function alertHtml(string $message, string $type = 'success'): string
    {
        if (empty($message)) return '';
        $cssType = $type === 'danger' ? 'alert-danger' : 'alert-success';
        return '<div class="alert ' . $cssType . ' alert-dismissible fade show">'
             . htmlspecialchars($message)
             . '<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>';
    }
}
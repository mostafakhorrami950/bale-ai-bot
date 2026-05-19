<?php

namespace Modules\Bot;

class Update
{
    private $data;

    public function __construct(array $data)
    {
        $this->data = $data;
    }

    public function getId(): ?int
    {
        return $this->data['update_id'] ?? null;
    }

    public function isMessage(): bool
    {
        return isset($this->data['message']) && !empty($this->data['message']);
    }

    public function isCallback(): bool
    {
        return isset($this->data['callback_query']);
    }

    public function getMessage(): array
    {
        return $this->data['message'] ?? [];
    }

    public function getCallbackQuery(): array
    {
        return $this->data['callback_query'] ?? [];
    }

    public function getChatId(): ?int
    {
        return $this->data['message']['chat']['id'] ?? $this->data['callback_query']['message']['chat']['id'] ?? null;
    }

    /**
     * Get the chat type: 'private', 'group', 'channel', or null.
     */
    public function getChatType(): ?string
    {
        return $this->data['message']['chat']['type'] 
            ?? $this->data['callback_query']['message']['chat']['type'] 
            ?? null;
    }

    /**
     * Check if the update is from a private chat (direct message to bot).
     */
    public function isPrivate(): bool
    {
        return $this->getChatType() === 'private';
    }

    /**
     * Check if the update is from a group or channel (should be ignored).
     */
    public function isGroupOrChannel(): bool
    {
        $type = $this->getChatType();
        return $type === 'group' || $type === 'channel' || $type === 'supergroup';
    }

    public function getUserId(): ?int
    {
        return $this->data['message']['from']['id'] 
            ?? $this->data['callback_query']['from']['id'] 
            ?? $this->data['callback_query']['message']['from']['id'] 
            ?? $this->data['pre_checkout_query']['from']['id']
            ?? $this->data['message']['successful_payment']['from']['id']
            ?? null;
    }

    public function getFirstName(): ?string
    {
        return $this->data['message']['from']['first_name'] ?? $this->data['callback_query']['from']['first_name'] ?? $this->data['callback_query']['message']['from']['first_name'] ?? null;
    }

    public function getUsername(): ?string
    {
        return $this->data['message']['from']['username'] ?? $this->data['callback_query']['from']['username'] ?? $this->data['callback_query']['message']['from']['username'] ?? null;
    }

    public function getText(): string
    {
        $text = $this->data['message']['text'] ?? $this->data['callback_query']['data'] ?? '';
        return (string) $text;
    }

    /**
     * Extract deep link payload from /start <payload> command.
     * Example: "/start instagram" returns "instagram".
     * Returns null if no payload or not a /start command.
     */
    public function getDeepLinkPayload(): ?string
    {
        $text = $this->data['message']['text'] ?? '';
        if (strpos($text, '/start ') !== 0) {
            return null;
        }
        $parts = explode(' ', $text, 2);
        if (count($parts) < 2) {
            return null;
        }
        $payload = trim($parts[1]);
        return $payload !== '' ? $payload : null;
    }

    public function getCallbackId(): ?string
    {
        return $this->data['callback_query']['id'] ?? null;
    }

    public function getCallbackData(): ?string
    {
        return $this->data['callback_query']['data'] ?? null;
    }

    public function getContact(): ?array
    {
        return $this->data['message']['contact'] ?? null;
    }

    /**
     * Check if the update contains a photo.
     */
    /**
     * Check if the update contains a photo.
     * M2: Fixed to prevent crash on empty photo array.
     */
    public function hasPhoto(): bool
    {
        if (!$this->isMessage()) return false;
        $photos = $this->data['message']['photo'] ?? [];
        return is_array($photos) && !empty($photos);
    }

    /**
     * Get the largest (best quality) photo file_id from the message.
     */
    public function getPhotoFileId(): ?string
    {
        if (!$this->hasPhoto()) return null;
        $photos = $this->data['message']['photo'];
        // Last element has the highest resolution
        $last = end($photos);
        return $last['file_id'] ?? null;
    }

    /**
     * Get photo file unique ID (for deduplication).
     */
    public function getPhotoFileUniqueId(): ?string
    {
        if (!$this->hasPhoto()) return null;
        $photos = $this->data['message']['photo'];
        $last = end($photos);
        return $last['file_unique_id'] ?? null;
    }

    /**
     * Check if the update contains a document (file).
     */
    public function hasDocument(): bool
    {
        if (!$this->isMessage()) return false;
        return isset($this->data['message']['document']) && !empty($this->data['message']['document']);
    }

    /**
     * Get the document file_id.
     */
    public function getDocumentFileId(): ?string
    {
        if (!$this->hasDocument()) return null;
        return $this->data['message']['document']['file_id'] ?? null;
    }

    /**
     * Get the document file name.
     */
    public function getDocumentFileName(): ?string
    {
        if (!$this->hasDocument()) return null;
        return $this->data['message']['document']['file_name'] ?? null;
    }

    /**
     * Check if the update contains a voice message.
     */
    public function hasVoice(): bool
    {
        if (!$this->isMessage()) return false;
        return isset($this->data['message']['voice']) && !empty($this->data['message']['voice']);
    }

    /**
     * Get the voice file_id.
     */
    public function getVoiceFileId(): ?string
    {
        if (!$this->hasVoice()) return null;
        return $this->data['message']['voice']['file_id'] ?? null;
    }

    /**
     * Get the voice MIME type (e.g. audio/ogg).
     */
    public function getVoiceMimeType(): ?string
    {
        if (!$this->hasVoice()) return null;
        return $this->data['message']['voice']['mime_type'] ?? 'audio/ogg';
    }

    /**
     * Check if the update contains an audio file (music, podcast, etc.).
     */
    public function hasAudio(): bool
    {
        if (!$this->isMessage()) return false;
        return isset($this->data['message']['audio']) && !empty($this->data['message']['audio']);
    }

    /**
     * Get the audio file_id.
     */
    public function getAudioFileId(): ?string
    {
        if (!$this->hasAudio()) return null;
        return $this->data['message']['audio']['file_id'] ?? null;
    }

    /**
     * Get the audio MIME type.
     */
    public function getAudioMimeType(): ?string
    {
        if (!$this->hasAudio()) return null;
        return $this->data['message']['audio']['mime_type'] ?? 'audio/mpeg';
    }

    /**
     * Check if the update contains a video message.
     */
    public function hasVideo(): bool
    {
        if (!$this->isMessage()) return false;
        return isset($this->data['message']['video']) && !empty($this->data['message']['video']);
    }

    /**
     * Get the video file_id.
     */
    public function getVideoFileId(): ?string
    {
        if (!$this->hasVideo()) return null;
        return $this->data['message']['video']['file_id'] ?? null;
    }

    /**
     * Get the video MIME type.
     */
    public function getVideoMimeType(): ?string
    {
        if (!$this->hasVideo()) return null;
        return $this->data['message']['video']['mime_type'] ?? 'video/mp4';
    }

    public function getMediaGroupId(): ?string
    {
        return $this->data['message']['media_group_id'] ?? null;
    }

    public function isDuplicate(): bool
    {
        try {
            $id = $this->getId();
            if (!$id) return false;

            $db = \Database\Database::getInstance();
            $stmt = $db->prepare("SELECT 1 FROM processed_updates WHERE update_id = ?");
            $stmt->execute([$id]);
            return (bool)$stmt->fetch();
        } catch (\Exception $e) {
            return false; // Fail safe
        }
    }

    public function markAsProcessed(): void
    {
        try {
            $id = $this->getId();
            if (!$id) return;

            $db = \Database\Database::getInstance();
            $stmt = $db->prepare("INSERT IGNORE INTO processed_updates (update_id) VALUES (?)");
            $stmt->execute([$id]);
        } catch (\Exception $e) {
            // Silent failure for process marking
        }
    }

    public function getRaw(): array
    {
        return $this->data;
    }

    // ─── PAYMENT METHODS ───

    /**
     * Check if the update is a pre_checkout_query (Bale wallet payment confirmation).
     */
    public function isPreCheckoutQuery(): bool
    {
        return isset($this->data['pre_checkout_query']);
    }

    /**
     * Get the pre_checkout_query data.
     */
    public function getPreCheckoutQuery(): ?array
    {
        return $this->data['pre_checkout_query'] ?? null;
    }

    /**
     * Get the pre_checkout_query ID.
     */
    public function getPreCheckoutQueryId(): ?string
    {
        return $this->data['pre_checkout_query']['id'] ?? null;
    }

    /**
     * Check if the update contains a successful_payment.
     */
    public function isSuccessfulPayment(): bool
    {
        return isset($this->data['message']['successful_payment']);
    }

    /**
     * Get the successful_payment data.
     */
    public function getSuccessfulPayment(): ?array
    {
        return $this->data['message']['successful_payment'] ?? null;
    }
}

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

    public function getUserId(): ?int
    {
        return $this->data['message']['from']['id'] ?? $this->data['callback_query']['from']['id'] ?? $this->data['callback_query']['message']['from']['id'] ?? null;
    }

    public function getText(): string
    {
        $text = $this->data['message']['text'] ?? $this->data['callback_query']['data'] ?? '';
        return (string) $text;
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
}
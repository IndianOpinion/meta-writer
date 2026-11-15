<?php

declare(strict_types=1);

namespace IndianOpinion\MetaWriter;

/**
 * One generated, validated pair held for review. Read-only.
 */
final class Suggestion
{
    /**
     * @param list<string> $fields
     * @param list<string> $flags
     */
    public function __construct(
        public readonly int $id,
        public readonly int $postId,
        public readonly array $fields,
        public readonly string $title,
        public readonly string $description,
        public readonly int $titleLength,
        public readonly int $descriptionLength,
        public readonly array $flags,
        public readonly string $status,
        public readonly string $inputHash,
        public readonly string $route,
        public readonly string $promptVersion,
        public readonly int $createdAt,
        public readonly int $createdBy = 0,
        public readonly int $decidedBy = 0,
        public readonly int $decidedAt = 0,
        public readonly string $error = '',
        public readonly string $errorClass = '',
        public readonly ?string $raw = null,
        public readonly string $descriptionHash = '',
    ) {
    }

    public function hasField(string $field): bool
    {
        return in_array($field, $this->fields, true);
    }

    public function hasFlag(string $flag): bool
    {
        return in_array($flag, $this->flags, true);
    }

    public function text(string $field): string
    {
        return $field === Field::TITLE ? $this->title : $this->description;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'post_id' => $this->postId,
            'fields' => $this->fields,
            'title' => $this->title,
            'description' => $this->description,
            'title_length' => $this->titleLength,
            'description_length' => $this->descriptionLength,
            'flags' => $this->flags,
            'status' => $this->status,
            'input_hash' => $this->inputHash,
            'route' => $this->route,
            'prompt_version' => $this->promptVersion,
            'created_at' => $this->createdAt,
            'created_by' => $this->createdBy,
            'decided_by' => $this->decidedBy,
            'decided_at' => $this->decidedAt,
            'error' => $this->error,
            'error_class' => $this->errorClass,
        ];
    }
}

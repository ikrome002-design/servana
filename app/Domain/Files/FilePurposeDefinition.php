<?php

declare(strict_types=1);

namespace App\Domain\Files;

use App\Domain\Files\Enums\FilePurpose;

/**
 * Immutable per-purpose policy record (Plan §65; Phase 10F). The single registry
 * {@see FilePurposeRegistry} owns one of these per {@see FilePurpose}.
 */
final readonly class FilePurposeDefinition
{
    /**
     * @param  list<string>  $extensions  allowed lowercase extensions (uploadable purposes)
     * @param  list<string>  $mimeTypes  allowed server-detected MIME types
     */
    public function __construct(
        public FilePurpose $purpose,
        public bool $uploadable,
        public string $ownerPhase,
        public array $extensions,
        public array $mimeTypes,
        public int $maxBytes,
        public bool $requiresMerchant,
        public bool $requiresBranch,
        public bool $requiresOwner,
        public ?string $permission,
        public bool $sanitizeImage,
        public ?int $retentionDays,
        public bool $billingReadOnlyGeneration,
    ) {}

    public function allowsExtension(string $extension): bool
    {
        return in_array(strtolower($extension), $this->extensions, true);
    }

    public function allowsMime(string $mime): bool
    {
        return in_array(strtolower($mime), $this->mimeTypes, true);
    }
}

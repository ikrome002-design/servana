<?php

declare(strict_types=1);

namespace App\Domain\Files;

use App\Domain\Files\Enums\FileScanResult;

/**
 * Result of a single malware scan (Phase 10F). Carries only safe metadata — never
 * the scanner's raw response or any file/payload bytes.
 */
final readonly class ScanOutcome
{
    public function __construct(
        public FileScanResult $result,
        public string $scanner,
        public ?string $engineVersion = null,
        public ?string $signatureVersion = null,
        public ?string $malwareName = null,
        public ?string $errorCode = null,
    ) {}

    public function isClean(): bool
    {
        return $this->result === FileScanResult::Clean;
    }

    public function isInfected(): bool
    {
        return $this->result === FileScanResult::Infected;
    }
}

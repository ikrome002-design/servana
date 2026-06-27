<?php

declare(strict_types=1);

namespace App\Domain\Files\Services;

use App\Domain\Files\Contracts\FileScanner;
use App\Domain\Files\Enums\FileScanResult;
use App\Domain\Files\ScanOutcome;

/**
 * Deterministic in-memory scanner for focused tests (Phase 10F). The real ClamAV
 * EICAR integration test uses {@see ClamAvScanner}, not this fake.
 */
final class FakeFileScanner implements FileScanner
{
    public int $calls = 0;

    public function __construct(private ScanOutcome $outcome) {}

    public static function clean(): self
    {
        return new self(new ScanOutcome(FileScanResult::Clean, 'fake', 'fake-1.0', '1'));
    }

    public static function infected(string $name = 'Test-Signature'): self
    {
        return new self(new ScanOutcome(FileScanResult::Infected, 'fake', 'fake-1.0', '1', malwareName: $name));
    }

    public static function error(string $code = 'scan_error'): self
    {
        return new self(new ScanOutcome(FileScanResult::Error, 'fake', errorCode: $code));
    }

    /** @param resource $stream */
    public function scanResource($stream): ScanOutcome
    {
        $this->calls++;

        // Drain the stream so callers exercise the same read path as production.
        while (! feof($stream)) {
            if (fread($stream, 8192) === false) {
                break;
            }
        }

        return $this->outcome;
    }
}

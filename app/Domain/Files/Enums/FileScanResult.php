<?php

declare(strict_types=1);

namespace App\Domain\Files\Enums;

/** Result of a single malware scan, recorded on `file_scan_events` (Phase 10F). */
enum FileScanResult: string
{
    case Clean = 'clean';
    case Infected = 'infected';
    case Error = 'error';
}

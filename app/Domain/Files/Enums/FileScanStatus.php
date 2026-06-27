<?php

declare(strict_types=1);

namespace App\Domain\Files\Enums;

/** Malware-scan verdict on an uploaded file (Plan §65; Phase 10F). */
enum FileScanStatus: string
{
    case Pending = 'pending';
    case Clean = 'clean';
    case Infected = 'infected';
    case ScanFailed = 'scan_failed';
    case Rejected = 'rejected';
}

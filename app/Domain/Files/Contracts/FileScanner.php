<?php

declare(strict_types=1);

namespace App\Domain\Files\Contracts;

use App\Domain\Files\ScanOutcome;

/**
 * Malware-scanner contract (Plan §65, §73; Phase 10F). Implementations stream the
 * bytes to the engine (never load the whole file into memory) and map the verdict
 * to a safe {@see ScanOutcome}. The production adapter is ClamAV INSTREAM; tests
 * may bind a deterministic fake (the real ClamAV EICAR test uses the real adapter).
 *
 * @param  resource  $stream
 */
interface FileScanner
{
    /** @param resource $stream */
    public function scanResource($stream): ScanOutcome;
}

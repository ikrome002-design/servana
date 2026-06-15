<?php

declare(strict_types=1);

namespace App\Domain\Audit\Contracts;

use App\Domain\Audit\Enums\AuditSeverity;
use App\Domain\Audit\Models\AuditLog;
use App\Models\User;

/**
 * Records audit events (Plan §22.2). Introduced in Phase 8 so the financial
 * phases (17–18) and Phase 19 have a stable seam; the table-backed
 * implementation lands here, full §5.18 event coverage matures in Phase 19.
 */
interface AuditRecorder
{
    /**
     * Append an immutable, hash-chained audit record.
     *
     * @param  array<string, mixed>  $context  Redacted, non-secret event detail.
     */
    public function record(
        string $action,
        AuditSeverity $severity,
        ?User $actor = null,
        ?int $merchantId = null,
        ?object $subject = null,
        array $context = [],
    ): AuditLog;
}

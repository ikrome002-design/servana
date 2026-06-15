<?php

declare(strict_types=1);

namespace App\Domain\Audit\Enums;

/**
 * Audit event severity (Plan §22.2). Mirrors the audit_logs.severity DB CHECK.
 * Permission changes are recorded `High`; denied escalation/audit-write attempts
 * are `Warning`.
 */
enum AuditSeverity: string
{
    case Info = 'info';
    case Notice = 'notice';
    case Warning = 'warning';
    case High = 'high';
    case Critical = 'critical';
}

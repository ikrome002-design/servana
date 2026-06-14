<?php

declare(strict_types=1);

namespace App\Domain\Auth\Enums;

/**
 * Authentication audit events (Plan §9.1, §22.2).
 *
 * Until the hash-chained `audit_logs` table lands in Phase 8, these are emitted
 * to the structured, redacted log channel via AuthEventLogger. The names are the
 * same ones Phase 8 will record as audit actions, so the migration is a backend
 * swap with no behavioural change.
 */
enum AuthEvent: string
{
    case LinkRequested = 'login_link_requested';
    case LinkDenied = 'login_link_denied';
    case LinkFailed = 'login_link_failed';
    case LoginSuccess = 'login_success';
    case Logout = 'logout';
}

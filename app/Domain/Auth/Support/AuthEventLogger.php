<?php

declare(strict_types=1);

namespace App\Domain\Auth\Support;

use App\Domain\Auth\Enums\AuthEvent;
use Illuminate\Support\Facades\Log;

/**
 * Interim auth audit sink (Plan §9.1, §22.2).
 *
 * Writes structured auth events to the redacted log channel until the
 * hash-chained `audit_logs` table is introduced in Phase 8, at which point this
 * is swapped for AuditRecorder with no change to call sites.
 *
 * Hard rule (Plan §3 rule 6, §3 rule 14): never log raw tokens, full Magic Link
 * URLs, or full credentials. Only event metadata is recorded; the email is
 * masked so logs cannot be used to enumerate accounts.
 */
final class AuthEventLogger
{
    public function record(AuthEvent $event, ?string $email = null, ?string $reason = null, ?string $userUlid = null): void
    {
        Log::info('auth.'.$event->value, array_filter([
            'event' => $event->value,
            'email' => $email !== null ? $this->maskEmail($email) : null,
            'reason' => $reason,
            'user_ulid' => $userUlid,
        ], static fn (mixed $v): bool => $v !== null));
    }

    /** j***@e***.com — enough to correlate, not enough to enumerate. */
    private function maskEmail(string $email): string
    {
        $parts = explode('@', $email, 2);
        $local = $parts[0] !== '' ? $parts[0][0].'***' : '***';

        if (count($parts) < 2) {
            return $local;
        }

        $domain = $parts[1];
        $dot = strrpos($domain, '.');
        $tld = $dot !== false ? substr($domain, $dot) : '';

        return $local.'@'.($domain !== '' ? $domain[0] : '').'***'.$tld;
    }
}

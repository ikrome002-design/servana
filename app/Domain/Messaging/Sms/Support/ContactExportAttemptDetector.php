<?php

declare(strict_types=1);

namespace App\Domain\Messaging\Sms\Support;

use App\Domain\Audit\Contracts\AuditRecorder;
use App\Domain\Audit\Enums\AuditEvent;
use App\Domain\Tenancy\TenantContext;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * Detects and audits a guessed CONTACT-EXPORT-shaped request (ADR-010: *"guessed export routes 404
 * + high-severity audit"*; Plan §64, §73 "personnel contact extraction"; Phase 21S).
 *
 * There is NO contact-export route in Servana and there never will be — this class exists because
 * the absence of a route is silent, and the Plan requires the ATTEMPT to be visible. It is invoked
 * from the exception pipeline when a request 404s, so a probe reaches the application, is recorded
 * at HIGH severity, and still gets the same unremarkable 404 an unknown path always gets. Nothing
 * about the response changes: the probe learns nothing.
 *
 * SCOPED ON PURPOSE. It fires only for a path that is BOTH inside the personnel SMS / served-client
 * surface AND carries an export-shaped token. That precision matters — Servana has several
 * legitimate download routes (`files.download`, `finance-exports.download-link`,
 * `receipts.download-link`, `audit-exports.download`), and a mistyped URL on one of those must not
 * be recorded as a contact-extraction attempt.
 *
 * The audit context carries the sanitised PATH ONLY — never the query string, never a body, never a
 * client identity and never a contact (Plan §24.5).
 */
final class ContactExportAttemptDetector
{
    /**
     * Path fragments that place a request inside the personnel SMS / served-client surface.
     *
     * @var list<string>
     */
    private const SURFACE_FRAGMENTS = [
        'personnel/me/served-clients',
        'personnel/served-clients',
        'personnel/me/sms',
        'personnel/sms',
        'served-clients',
    ];

    /**
     * Tokens that make a request export-shaped. Segment-matched, so `sms-campaigns` never trips on
     * a substring.
     *
     * @var list<string>
     */
    private const EXPORT_TOKENS = [
        'export', 'exports', 'download', 'downloads', 'print', 'copy', 'clipboard',
        'csv', 'xlsx', 'xls', 'pdf', 'vcard', 'vcf', 'contacts', 'phones', 'numbers', 'phone-list',
    ];

    public function __construct(
        private readonly AuditRecorder $audit,
        private readonly TenantContext $context,
    ) {}

    /** Whether this path is a contact-export probe against the personnel SMS surface. */
    public function isExportShaped(string $path): bool
    {
        $normalized = strtolower(trim($path, '/'));

        $inSurface = false;

        foreach (self::SURFACE_FRAGMENTS as $fragment) {
            if (str_contains($normalized, $fragment)) {
                $inSurface = true;

                break;
            }
        }

        if (! $inSurface) {
            return false;
        }

        foreach (explode('/', $normalized) as $segment) {
            if (in_array($segment, self::EXPORT_TOKENS, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Record the attempt when the request is export-shaped. Returns true when an audit row was
     * written. Never throws — a detector failure must not change the 404 the caller is about to
     * return.
     */
    public function recordIfExportShaped(Request $request): bool
    {
        $path = $request->path();

        if (! $this->isExportShaped($path)) {
            return false;
        }

        $user = $request->user();

        $this->audit->record(
            AuditEvent::PersonnelSmsExportAttemptBlocked,
            $user instanceof User ? $user : null,
            $this->context->merchantId(),
            null,
            null,
            [
                // Path only — no query string (it could carry a guessed identifier), no body,
                // no client identity, no contact (Plan §24.5).
                'path' => '/'.trim($path, '/'),
                'method' => $request->getMethod(),
                'outcome' => 'not_found',
            ],
        );

        return true;
    }
}

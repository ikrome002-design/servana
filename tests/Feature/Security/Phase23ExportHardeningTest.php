<?php

declare(strict_types=1);

use App\Domain\Audit\Actions\ExpireAuditExport;
use App\Domain\Audit\Actions\RevokeAuditExport;
use App\Domain\Audit\Models\AuditExport;
use App\Domain\Files\Enums\FilePurpose;
use App\Domain\Files\FilePurposeRegistry;
use App\Domain\FinanceOps\Actions\ExpireFinanceExport;
use App\Domain\FinanceOps\Actions\RevokeFinanceExport;
use App\Domain\FinanceOps\Enums\FinanceExportType;
use App\Domain\FinanceOps\Jobs\GenerateFinanceExport;
use App\Domain\FinanceOps\Models\FinanceExport;
use App\Domain\Merchants\Enums\MerchantUserRole;
use App\Domain\Payments\Enums\PaymentMethod;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;

uses(RefreshDatabase::class)->group('security', 'phase23', 'export-hardening');

beforeEach(function (): void {
    Storage::fake((string) config('files.disk'));
});

/*
 |==============================================================================
 | Phase 23 Increment 4 — export / download hardening (Plan §65, §67, §74, §13.5).
 |
 | Helpers below are file-local with UNIQUE names on purpose. A Pest file-scope
 | function is a GLOBAL PHP function: a generic name collides across suites and a
 | function defined in another test file is invisible to a parallel worker that
 | only loaded this one (cf. PH23-TEST-001).
 */

/**
 * The canonical Phase 23 export/download surface matrix.
 *
 * Every live route that generates, links to, or serves an exported/generated document
 * MUST appear here with its controls. `docs/security/phase-23-export-hardening.md` is the
 * human-readable rendering of this array; the cases below keep the two honest and fail
 * when a new export surface ships unclassified.
 *
 * kind:      request | read | link | stream | generate
 * controls:  the control tokens this surface is required to carry (see the cases below).
 *
 * @var array<string, array{kind: string, controls: list<string>}>
 */
const P23_EXPORT_SURFACES = [
    // --- Finance exports (Plan §65, §67; Gate I) --------------------------------
    'finance-exports.index' => ['kind' => 'read', 'controls' => ['auth', 'authorization', 'tenant']],
    'finance-exports.show' => ['kind' => 'read', 'controls' => ['auth', 'authorization', 'tenant']],
    'finance-exports.store' => ['kind' => 'request', 'controls' => ['auth', 'permission', 'fresh_mfa', 'reason', 'tenant']],
    'finance-exports.download-link' => ['kind' => 'link', 'controls' => ['auth', 'permission', 'tenant', 'file_boundary', 'download_count']],
    'finance-exports.revoke' => ['kind' => 'request', 'controls' => ['auth', 'permission', 'tenant']],

    // --- Audit exports (Plan §13.5; ADR-010) ------------------------------------
    'audit-exports.index' => ['kind' => 'read', 'controls' => ['auth', 'permission', 'tenant']],
    'audit-exports.show' => ['kind' => 'read', 'controls' => ['auth', 'permission', 'tenant']],
    'audit-exports.store' => ['kind' => 'request', 'controls' => ['auth', 'permission', 'fresh_mfa', 'branch_scope', 'reason', 'tenant']],
    'audit-exports.download-link' => ['kind' => 'link', 'controls' => ['auth', 'permission', 'branch_scope', 'tenant', 'file_boundary']],
    'audit-exports.download' => ['kind' => 'stream', 'controls' => ['auth', 'signed', 'authorization', 'tenant', 'file_boundary', 'download_count']],
    'audit-exports.revoke' => ['kind' => 'request', 'controls' => ['auth', 'permission', 'branch_scope', 'tenant']],

    // --- Phase 10F file domain — the shared byte boundary (Plan §65) -------------
    'files.show' => ['kind' => 'read', 'controls' => ['auth', 'authorization', 'tenant', 'file_boundary']],
    'files.download-link' => ['kind' => 'link', 'controls' => ['auth', 'authorization', 'tenant', 'file_boundary']],
    'files.download' => ['kind' => 'stream', 'controls' => ['auth', 'signed', 'authorization', 'tenant', 'file_boundary']],

    // --- Receipt PDFs (Plan §43) -------------------------------------------------
    'receipts.index' => ['kind' => 'read', 'controls' => ['auth', 'permission', 'tenant']],
    'receipts.show' => ['kind' => 'read', 'controls' => ['auth', 'permission', 'tenant']],
    'receipts.download-link' => ['kind' => 'link', 'controls' => ['auth', 'permission', 'tenant', 'file_boundary']],

    // --- Platform billing invoice PDFs (Plan §49) --------------------------------
    'subscription-invoices.index' => ['kind' => 'read', 'controls' => ['auth', 'permission', 'tenant']],
    'subscription-invoices.show' => ['kind' => 'read', 'controls' => ['auth', 'permission', 'tenant']],
    'subscription-invoices.pdf.generate' => ['kind' => 'generate', 'controls' => ['auth', 'permission', 'billing_gated', 'tenant']],
    'subscription-invoices.pdf.download-link' => ['kind' => 'link', 'controls' => ['auth', 'permission', 'tenant', 'file_boundary']],

    // --- Personnel earnings statements — own-scope (Plan §63) --------------------
    'personnel.statements.generate' => ['kind' => 'generate', 'controls' => ['auth', 'permission', 'billing_gated', 'own_scope', 'tenant', 'file_boundary']],
];

/**
 * Export/report-shaped route names that are NOT document surfaces, with the reason.
 * Keeps the "nothing escapes the matrix" case from drowning in false positives while
 * still forcing every genuinely new export route to be classified.
 *
 * @var array<string, string>
 */
const P23_NON_DOCUMENT_ROUTES = [
    'files.store' => 'Upload intake (Plan §65 pipeline), not a document read/export surface. Covered by FileUploadValidationTest / FileScanPipelineTest.',
    'invoices.index' => 'Customer invoice LIST — a normal permissioned read, not a document download. No file is served.',
    'invoices.show' => 'Customer invoice DETAIL — a normal permissioned read. The PDF, when a phase attaches one, is served through the file domain.',
    'invoices.store' => 'Invoice creation, not a document surface.',
    'invoices.update' => 'Invoice mutation, not a document surface.',
    'invoices.adjust' => 'Invoice adjustment, not a document surface.',
    'invoices.finalize' => 'Invoice finalisation, not a document surface.',
    'invoices.void' => 'Invoice void request, not a document surface.',
    'invoices.void.execute' => 'Invoice void execution, not a document surface.',
    'invoices.void.reject' => 'Invoice void rejection, not a document surface.',
    'receipts.reissue' => 'Issues a NEW receipt row (Plan §43); the PDF it produces is downloaded through receipts.download-link, which is in the matrix.',
    'payment-recording-groups.store' => 'Payment recording, not a document surface.',
    'payment-recording-groups.exception' => 'Payment exception recording, not a document surface.',

    // Phase UI-10 — Branch Manager financial visibility is JSON-only and read-only.
    'branches.financial-visibility.invoices' => 'Branch invoice LIST — a paginated, branch-scoped JSON visibility projection. It produces no file, signed link, or download accounting surface.',

    // Phase UI-08 (COR-UI08-001) — Subscription Operations monitoring reads.
    'platform.subscription-invoices.index' => 'Platform SUBSCRIPTION-invoice list — a paginated, permissioned JSON projection for platform monitoring. No file is produced, no signed link is issued, and there is no download accounting to harden. Distinct from the merchant-client `invoices.*` surface above.',
    'platform.subscription-invoices.show' => 'Platform SUBSCRIPTION-invoice detail — the same paginated JSON projection for one record. An issued subscription invoice is immutable and this route serves no document; a PDF, if a later phase attaches one, would be served through the file domain and would enter this matrix then.',
];

/** Request a finance export as the Finance user with a fresh step-up. */
function p23RequestFinanceExport(User $finance, array $body): TestResponse
{
    return test()->statefulMfa(now()->getTimestamp())->actingAs($finance, 'sanctum')
        ->postJson('/api/v1/finance-exports', $body);
}

/** A ready finance export with one validated payment row in it. */
function p23ReadyFinanceExport(array $scn): FinanceExport
{
    confirmedTotp($scn['finance']);
    cashUpValidatedComponent($scn, PaymentMethod::Cash, 100000);

    $ulid = (string) p23RequestFinanceExport($scn['finance'], [
        'export_type' => 'payments',
        'reason' => 'Phase 23 export hardening.',
    ])->assertCreated()->json('data.id');

    $export = FinanceExport::query()->where('ulid', $ulid)->firstOrFail();
    (new GenerateFinanceExport($export->id, $export->merchant_id, $export->branch_id))->handle();

    return $export->refresh();
}

/** @return array<string, Illuminate\Routing\Route> live api/v1 routes by name */
function p23LiveRoutes(): array
{
    $byName = [];
    foreach (Route::getRoutes()->getRoutes() as $route) {
        $uri = $route->uri();
        if (! str_starts_with($uri, 'api/v1/') || str_starts_with($uri, 'api/v1/testing/')) {
            continue;
        }
        $name = (string) $route->getName();
        if ($name !== '') {
            $byName[$name] = $route;
        }
    }

    return $byName;
}

/** Controller source for a route, so a boundary implemented in a helper is still visible. */
function p23ControllerSource(Illuminate\Routing\Route $route): string
{
    $action = $route->getActionName();
    if (! str_contains($action, '@')) {
        return '';
    }
    [$class] = explode('@', $action);

    return class_exists($class)
        ? (string) file_get_contents((string) (new ReflectionClass($class))->getFileName())
        : '';
}

/** The file ULID a caller genuinely learns from the signed download URL it was issued. */
function p23FileUlidFromSignedUrl(string $url): string
{
    $path = (string) parse_url($url, PHP_URL_PATH);
    preg_match('#/files/([0-9A-Za-z]{26})/download#', $path, $m);

    return $m[1] ?? '';
}

// --- Matrix enforcement -------------------------------------------------------

it('classifies every export/document-shaped route — nothing escapes the matrix', function (): void {
    $unclassified = [];

    foreach (p23LiveRoutes() as $name => $route) {
        $shaped = preg_match('/export|download|pdf|statement|receipt|invoice|report|document/i', $name.' '.$route->uri()) === 1;
        if (! $shaped) {
            continue;
        }
        if (array_key_exists($name, P23_EXPORT_SURFACES) || array_key_exists($name, P23_NON_DOCUMENT_ROUTES)) {
            continue;
        }
        $unclassified[] = sprintf('%s (%s %s)', $name, implode('|', $route->methods()), $route->uri());
    }

    expect($unclassified)->toBe([], implode("\n", array_merge(
        ['Export/document-shaped routes missing from the Phase 23 export-hardening matrix:'],
        $unclassified,
        ['', 'Add each to P23_EXPORT_SURFACES with its controls, or to P23_NON_DOCUMENT_ROUTES with a reason.'],
    )));
});

it('keeps the matrix honest — every classified route is still live', function (): void {
    $live = p23LiveRoutes();

    $stale = [];
    foreach (array_keys(P23_EXPORT_SURFACES) as $name) {
        if (! isset($live[$name])) {
            $stale[] = "P23_EXPORT_SURFACES: {$name}";
        }
    }
    foreach (array_keys(P23_NON_DOCUMENT_ROUTES) as $name) {
        if (! isset($live[$name])) {
            $stale[] = "P23_NON_DOCUMENT_ROUTES: {$name}";
        }
    }

    expect($stale)->toBe([], "Matrix entries for routes that no longer exist (delete them):\n".implode("\n", $stale));

    foreach (P23_NON_DOCUMENT_ROUTES as $name => $reason) {
        expect(strlen($reason))->toBeGreaterThan(40, "{$name}: say WHY this is not a document surface");
    }
});

it('enforces the declared control set on every export surface', function (): void {
    $live = p23LiveRoutes();
    $problems = [];

    foreach (P23_EXPORT_SURFACES as $name => $spec) {
        $route = $live[$name] ?? null;
        if ($route === null) {
            continue; // covered by the staleness case above
        }

        $middleware = implode('|', $route->gatherMiddleware());
        $source = p23ControllerSource($route);

        foreach ($spec['controls'] as $control) {
            // NOTE: gatherMiddleware() yields Laravel ALIASES for the framework middleware
            // (`auth:sanctum`, `signed`) and FQCNs for the app's own — match both forms.
            $ok = match ($control) {
                'auth' => str_contains($middleware, 'auth:sanctum') || str_contains($middleware, 'Authenticate:sanctum'),
                'tenant' => str_contains($middleware, 'ResolveTenantContext') && str_contains($middleware, 'EnsureMerchantActive'),
                'permission' => str_contains($middleware, 'EnsurePermission'),
                'fresh_mfa' => str_contains($middleware, 'RequireFreshMfa'),
                'branch_scope' => str_contains($middleware, 'EnsureBranchScope'),
                'billing_gated' => str_contains($middleware, 'EnsureBillingMutable'),
                'signed' => str_contains($middleware, 'signed') || str_contains($middleware, 'ValidateSignature'),
                // A real authorization call in the controller (policy, gate, own-scope
                // permission assertion, or the file access service).
                'authorization' => preg_match('/\$this->authorize\(|Gate::|->authorizeView\(|->authorizeDownload\(|abort_unless\(\s*\$this->context->can\(/', $source) === 1,
                // Bytes and links are only ever produced through the Phase 10F boundary.
                'file_boundary' => str_contains($source, 'FileAccessService'),
                'own_scope' => str_contains($source, 'ownStaffProfileOrFail') || str_contains($source, 'owner_user_id') || str_contains($source, 'staff_profile_id'),
                'download_count' => preg_match('/download_count|RecordAuditExportDownload/', $source) === 1,
                'reason' => str_contains($source, "validated('reason')"),
                default => false,
            };

            if (! $ok) {
                $problems[] = "{$name}: declared control '{$control}' is NOT present";
            }
        }
    }

    expect($problems)->toBe([], implode("\n", $problems));
});

it('never lets a raw byte stream be reachable without a signature and a re-check', function (): void {
    $live = p23LiveRoutes();

    foreach (P23_EXPORT_SURFACES as $name => $spec) {
        if ($spec['kind'] !== 'stream') {
            continue;
        }
        $route = $live[$name] ?? null;
        expect($route)->not->toBeNull("{$name}: stream route missing");

        $middleware = implode('|', $route->gatherMiddleware());
        expect(str_contains($middleware, 'signed') || str_contains($middleware, 'ValidateSignature'))
            ->toBeTrue("{$name}: a byte stream must require a valid signature");
        expect(str_contains($middleware, 'auth:sanctum') || str_contains($middleware, 'Authenticate:sanctum'))
            ->toBeTrue("{$name}: a signature is transport, never authentication");

        // …and the signature must not be the authorization: the controller re-checks.
        $source = p23ControllerSource($route);
        expect(str_contains($source, 'authorizeDownload'))->toBeTrue("{$name}: must re-check authorization at stream time");
    }
});

it('requires every generated file purpose to declare a real download authority', function (): void {
    // The mechanical guard that PH23-EXP-002 escaped: a generated purpose whose
    // `permission` is null and which is not owner-scoped is authorised by tenant
    // membership alone — every member of the merchant can pull it (Plan §65).
    $unauthorised = [];

    foreach (FilePurposeRegistry::all() as $key => $definition) {
        if ($definition->uploadable) {
            continue; // upload purposes are authorised at intake, covered by the 10F suites
        }
        if ($definition->permission !== null || $definition->requiresOwner) {
            continue;
        }
        $unauthorised[] = $key;
    }

    expect($unauthorised)->toBe([], implode("\n", array_merge(
        ['Generated file purposes with NO resource permission and NO owner scope:'],
        $unauthorised,
        ['', 'Plan §65 requires tenant ownership + branch scope + RESOURCE PERMISSION + purpose + status.'],
        ['Give the purpose an EXISTING permission key or make it owner-scoped.'],
    )));
});

it('keeps every export purpose generated-only and retention-bounded', function (): void {
    $problems = [];

    foreach ([FilePurpose::FinanceExport, FilePurpose::AuditExport, FilePurpose::EarningsStatement] as $purpose) {
        $definition = FilePurposeRegistry::for($purpose);

        if ($definition->uploadable) {
            $problems[] = "{$purpose->value}: an export purpose must never be client-uploadable";
        }
        if ($definition->retentionDays === null) {
            $problems[] = "{$purpose->value}: an export must expire (Plan §74)";
        }
        if (! $definition->billingReadOnlyGeneration) {
            $problems[] = "{$purpose->value}: new generation must be blocked during billing read-only (Plan §65)";
        }
    }

    expect($problems)->toBe([], implode("\n", $problems));
});

it('offers no export type that yields personnel or client contact data', function (): void {
    // Plan §64/§74, ADR-010: no contact-export channel exists anywhere.
    $types = array_map(
        static fn (FinanceExportType $t): string => $t->value,
        FinanceExportType::cases(),
    );

    foreach ($types as $type) {
        expect($type)->not->toMatch('/contact|phone|roster|personnel|staff|client/i');
    }

    // …and the builder never selects a contact-bearing column.
    $builder = (string) file_get_contents(base_path('app/Domain/FinanceOps/Services/FinanceExportCsvBuilder.php'));
    foreach (['phone', 'email', 'reference_normalized', 'reference_display_encrypted'] as $forbidden) {
        expect($builder)->not->toContain($forbidden, "the finance export CSV must never carry {$forbidden}");
    }
});

// --- Revocation / expiry propagation (PH23-EXP-001) ---------------------------

it('stops serving a REVOKED finance export through the generic file endpoints', function (): void {
    $scn = cashUpScenario();
    $export = p23ReadyFinanceExport($scn);

    // The caller legitimately receives a signed URL, which reveals the file ULID.
    $url = (string) test()->actingAs($scn['finance'], 'sanctum')
        ->postJson("/api/v1/finance-exports/{$export->ulid}/download-link")
        ->assertOk()->json('data.url');
    $fileUlid = p23FileUlidFromSignedUrl($url);
    expect($fileUlid)->not->toBe('', 'the signed download URL must address a file ULID');

    app(RevokeFinanceExport::class)->handle($export, $scn['finance']);

    // The export's own surface refuses (already covered by FinanceExportTest)...
    test()->actingAs($scn['finance'], 'sanctum')
        ->postJson("/api/v1/finance-exports/{$export->ulid}/download-link")->assertStatus(409);

    // ...and so must the generic file domain, or revocation is cosmetic.
    test()->actingAs($scn['finance'], 'sanctum')
        ->postJson("/api/v1/files/{$fileUlid}/download-link")->assertNotFound();
    test()->actingAs($scn['finance'], 'sanctum')->get($url)->assertNotFound();
});

it('stops serving an EXPIRED finance export through the generic file endpoints', function (): void {
    $scn = cashUpScenario();
    $export = p23ReadyFinanceExport($scn);

    $url = (string) test()->actingAs($scn['finance'], 'sanctum')
        ->postJson("/api/v1/finance-exports/{$export->ulid}/download-link")
        ->assertOk()->json('data.url');
    $fileUlid = p23FileUlidFromSignedUrl($url);

    app(ExpireFinanceExport::class)->handle($export);

    test()->actingAs($scn['finance'], 'sanctum')
        ->postJson("/api/v1/files/{$fileUlid}/download-link")->assertNotFound();
    test()->actingAs($scn['finance'], 'sanctum')->get($url)->assertNotFound();
});

it('stops serving a REVOKED audit export through the generic file endpoints', function (): void {
    $scn = auditExportScenario();
    $ulid = (string) requestAuditExport($scn['audit'], ['branch' => $scn['branch']->ulid, 'reason' => 'Review reason.'])
        ->assertCreated()->json('data.id');
    $export = AuditExport::query()->where('ulid', $ulid)->firstOrFail();
    runAuditExportJob($export);

    $fileUlid = (string) $export->refresh()->file?->ulid;
    expect($fileUlid)->not->toBe('');

    app(RevokeAuditExport::class)->handle($export, $scn['audit']);

    streamAuditExport($scn['audit'], $ulid)->assertStatus(409);
    test()->actingAs($scn['audit'], 'sanctum')
        ->postJson("/api/v1/files/{$fileUlid}/download-link")->assertNotFound();
});

it('gates the billing invoice PDF on its resource permission, not tenant membership alone', function (): void {
    [, $merchant] = activeAdmin();
    [$frontOffice] = memberWithRole(MerchantUserRole::FrontOffice, $merchant);
    [$personnel] = memberWithRole(MerchantUserRole::Personnel, $merchant);

    $pdf = availableFile($merchant->id, FilePurpose::BillingInvoicePdf);

    // Neither role holds merchant.subscription.invoice.download (Plan §19.3, §49).
    foreach ([$frontOffice, $personnel] as $actor) {
        test()->actingAs($actor, 'sanctum')
            ->postJson("/api/v1/files/{$pdf->ulid}/download-link")->assertStatus(403);
        test()->actingAs($actor, 'sanctum')
            ->getJson("/api/v1/files/{$pdf->ulid}")->assertStatus(403);
    }
});

it('stops serving an EXPIRED audit export through the generic file endpoints', function (): void {
    $scn = auditExportScenario();
    $ulid = (string) requestAuditExport($scn['audit'], ['branch' => $scn['branch']->ulid, 'reason' => 'Review reason.'])
        ->assertCreated()->json('data.id');
    $export = AuditExport::query()->where('ulid', $ulid)->firstOrFail();
    runAuditExportJob($export);

    $fileUlid = (string) $export->refresh()->file?->ulid;

    app(ExpireAuditExport::class)->handle($export);

    test()->actingAs($scn['audit'], 'sanctum')
        ->postJson("/api/v1/files/{$fileUlid}/download-link")->assertNotFound();
});

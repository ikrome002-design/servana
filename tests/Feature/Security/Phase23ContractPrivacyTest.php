<?php

declare(strict_types=1);

uses()->group('security', 'phase23', 'contract-privacy');

/*
 |==============================================================================
 | Phase 23 Increment 3.4 — public/generated contract privacy (Plan §9 rules 11–13, §24.5, §74).
 |
 | The OpenAPI document, the generated TypeScript contracts and any committed test artefact are
 | published surfaces: they are read by tooling, checked into the repository and shipped to the
 | SPA build. None of them may carry secret material, raw tokens, encrypted-column internals, or
 | unmasked contact data.
 |
 | This complements the redaction suites (which cover logs, errors and audit values) by covering
 | the artefacts those suites never look at.
 */

/** Committed contract artefacts that ship or are consumed by tooling. */
function p23ContractArtefacts(): array
{
    return [
        'docs/api/openapi.json',
        'resources/spa/src/types/generated/api.ts',
        'resources/spa/src/types/generated/permissions.ts',
    ];
}

/**
 * Column/field names that must never surface in a public contract. These are storage internals
 * (Plan §13) — the API exposes masked display fields, never the encrypted or index columns.
 */
const P23_FORBIDDEN_CONTRACT_FIELDS = [
    'phone_index',
    'phone_encrypted',
    'email_encrypted',
    'totp_secret',
    'recovery_code_hash',
    'token_hash',
    'magic_link_token',
    'webhook_secret',
    'signing_key',
];

it('publishes no storage-internal or secret field name in any generated contract', function (): void {
    $offending = [];

    foreach (p23ContractArtefacts() as $relative) {
        $path = base_path($relative);
        expect(is_file($path))->toBeTrue("{$relative} must exist");

        $body = strtolower((string) file_get_contents($path));
        foreach (P23_FORBIDDEN_CONTRACT_FIELDS as $field) {
            if (str_contains($body, $field)) {
                $offending[] = "{$relative}: {$field}";
            }
        }
    }

    expect($offending)->toBe([], "Storage-internal/secret field exposed in a published contract:\n".implode("\n", $offending));
});

it('publishes no credential-shaped literal in any generated contract', function (): void {
    $offending = [];

    foreach (p23ContractArtefacts() as $relative) {
        $body = (string) file_get_contents(base_path($relative));

        // Assignment of a non-empty secret-ish literal. Schema PROPERTY names such as
        // "password" are not the concern here (Servana has no passwords at all); a literal
        // VALUE is.
        if (preg_match('/"(api_key|secret|password|private_key|access_token)"\s*:\s*"[^"]{8,}"/i', $body, $m)) {
            $offending[] = "{$relative}: {$m[0]}";
        }
        // A live-looking bearer/JWT literal.
        if (preg_match('/eyJ[A-Za-z0-9_-]{20,}\./', $body)) {
            $offending[] = "{$relative}: JWT-shaped literal";
        }
    }

    expect($offending)->toBe([], "Credential-shaped literal in a published contract:\n".implode("\n", $offending));
});

it('publishes no absolute private storage path in any generated contract', function (): void {
    $offending = [];

    foreach (p23ContractArtefacts() as $relative) {
        $body = (string) file_get_contents(base_path($relative));

        // Storage paths are never exposed; downloads go through the authorized endpoint (Plan §65).
        if (preg_match('#(/var/www/html/storage/|[A-Za-z]:\\\\.*\\\\storage\\\\|s3://)#', $body, $m)) {
            $offending[] = "{$relative}: {$m[0]}";
        }
    }

    expect($offending)->toBe([], "Private storage path in a published contract:\n".implode("\n", $offending));
});

it('never exposes an unmasked phone field on a contact-bearing API resource', function (): void {
    // Client-facing resources expose masked contact only (Plan §74, guardrail §6.4). The staff
    // roster's `phone` is an HR-only field behind `staff.view` (PH23-SEC-001) and is asserted
    // separately; what must NOT exist is a masked-contact resource that also leaks the raw value.
    $offending = [];

    $resourceDir = app_path('Http/Resources');
    foreach (glob($resourceDir.'/*.php') ?: [] as $path) {
        $body = (string) file_get_contents($path);
        $name = basename($path);

        $hasMasked = str_contains($body, 'phone_masked') || str_contains($body, 'phone_last_four');
        $hasRaw = (bool) preg_match("/'phone'\s*=>\s*\\\$this->phone\b/", $body);

        if ($hasMasked && $hasRaw) {
            $offending[] = "{$name}: exposes BOTH a masked phone and the raw phone";
        }
    }

    expect($offending)->toBe([], implode("\n", $offending));
});

it('commits no Playwright artefact containing captured secrets or contact data', function (): void {
    // Traces/screenshots/videos must never be committed: they can capture rendered contact data
    // and request headers. Their absence from the repository is the control.
    $artefactDirs = [
        'test-results',
        'playwright-report',
        'tests/e2e/.auth',
    ];

    $present = [];
    foreach ($artefactDirs as $relative) {
        $path = base_path($relative);
        if (! is_dir($path)) {
            continue;
        }
        // Present on disk is fine (a local run); COMMITTED is not.
        $tracked = trim((string) shell_exec('git -C '.escapeshellarg(base_path()).' ls-files '.escapeshellarg($relative)));
        if ($tracked !== '') {
            $present[] = $relative;
        }
    }

    expect($present)->toBe([], 'Playwright artefacts are tracked in git and may contain captured secrets/contact data: '.implode(', ', $present));
});

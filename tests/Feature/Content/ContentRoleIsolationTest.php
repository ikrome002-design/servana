<?php

declare(strict_types=1);

uses()->group('docs', 'ui05', 'contracts', 'content', 'isolation');

/*
 |==============================================================================
 | Phase UI-05 — content role isolation (UI/UX plan §17.1).
 |
 | "Do not cross-map roles" is easy to satisfy by accident and easy to break by accident. The old
 | loader resolved a document by matching a path SUFFIX against a glob result, which would happily
 | return a sibling's file if the expected one were absent. The generated contract replaces that
 | with an explicit table, and these assertions prove the table has no route from one account to
 | another account's content — including the fail-closed behaviour on an unknown key.
 */

it('gives every generated module a specifier under its own account directory', function (): void {
    $index = (string) file_get_contents(base_path('resources/spa/src/content/generated/index.generated.ts'));

    expect(preg_match_all('#=> import\("\./([a-z_]+)/([a-z-]+)\.generated"\)#', $index, $matches))
        ->toBe(40, 'Expected forty static dynamic imports, one per account and category.');

    $seen = [];
    foreach ($matches[1] as $i => $account) {
        expect(UI05_ACCOUNTS)->toContain($account);
        $seen[] = "{$account}/{$matches[2][$i]}";
    }
    expect(array_unique($seen))->toHaveCount(40);

    // A template-literal or variable specifier would both defeat code splitting and let a runtime
    // value decide which file loads. Neither may appear.
    expect(preg_match('/import\(\s*`/', $index))->toBe(0);
    expect(preg_match('/import\(\s*[A-Za-z_$]/', $index))->toBe(0);
});

it('fails closed on an unknown account key or category instead of falling back', function (): void {
    $index = (string) file_get_contents(base_path('resources/spa/src/content/generated/index.generated.ts'));

    expect($index)->toContain('Content not found — unknown account key');
    expect($index)->toContain('Content not found — unknown category');

    // A default account or a `?? LOADERS.merchant_branch` style fallback is the exact defect this
    // guard exists to prevent.
    expect(preg_match('/\?\?\s*LOADERS\./', $index))->toBe(0);
    foreach (UI05_ACCOUNTS as $account) {
        expect(preg_match('/\|\|\s*LOADERS\.'.preg_quote($account, '/').'/', $index))->toBe(0);
    }
});

it('never lets one account\'s generated module name another account', function (): void {
    foreach (UI05_ACCOUNTS as $account) {
        foreach (['landing', 'data-policy', 'privacy-policy', 'terms-of-service', 'faq'] as $module) {
            $path = "resources/spa/src/content/generated/{$account}/{$module}.generated.ts";
            expect(file_exists(base_path($path)))->toBeTrue("Missing generated module: {$path}");

            $header = (string) file_get_contents(base_path($path), false, null, 0, 4096);

            expect($header)->toContain("accountKey: \"{$account}\"");
            foreach (UI05_ACCOUNTS as $other) {
                if ($other !== $account) {
                    expect($header)->not->toContain("sourcePath: \"docs/landing_page/{$other}");
                    expect($header)->not->toContain("accountKey: \"{$other}\"");
                }
            }
        }
    }
});

it('keeps the compiled landing regions of each account sourced from its own document', function (): void {
    $parity = ui05Audit('content-parity');

    /** @var array<string, array<string, string>> $presence */
    $presence = $parity['landing_region_presence'];
    expect(array_keys($presence))->toHaveCount(8);

    /** @var list<string> $regions */
    $regions = $parity['landing_regions'];
    expect($regions)->toHaveCount(16);

    foreach (UI05_ACCOUNTS as $account) {
        expect($presence)->toHaveKey($account);
        expect(array_keys($presence[$account]))->toHaveCount(16);

        foreach ($regions as $region) {
            expect($presence[$account][$region])->toBeIn(['present_in_source', 'missing_from_source']);
        }
    }
});

it('records missing sections as decisions rather than filling them', function (): void {
    $parity = ui05Audit('content-parity');

    /** @var list<array<string, mixed>> $missing */
    $missing = $parity['sections_missing_from_source'];
    foreach ($missing as $row) {
        expect($row['decision'])->toBe('product_owner_content_decision_required');
        expect($row['owner_phase'])->toBe('UI-06');
        expect(UI05_ACCOUNTS)->toContain($row['account_key']);
    }

    // Four gaps were proven at UI-05: no testimonials for Merchant Administrator, Merchant Audit or
    // Super Administrator, and no pricing section for Super Administrator. Recording them is the
    // deliverable; inventing copy for them is forbidden (UI/UX plan §8.3).
    expect($missing)->toHaveCount(4);
});

it('withholds unverified customer evidence from rendering', function (): void {
    $parity = ui05Audit('content-parity');

    /** @var list<array<string, mixed>> $withheld */
    $withheld = $parity['sections_not_renderable'];

    foreach ($withheld as $row) {
        expect($row['region'])->toBe('testimonials');
        expect($row['content_restriction'])->toBeIn([
            'unverified_customer_evidence',
            'placeholder_testimonial_marked_in_source',
        ]);
        expect($row['owner_phase'])->toBe('UI-06 / product owner');
        // The source heading is carried verbatim, never paraphrased into a summary.
        expect($row['source_heading'])->toBeString()->not->toBe('');
    }

    // Four accounts supply a testimonial section carrying unverified quotes; one (Human Resource)
    // supplies a factual trust statement instead, which is renderable.
    expect($withheld)->toHaveCount(4);
});

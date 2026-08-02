<?php

declare(strict_types=1);

uses()->group('design-system', 'ui-04', 'contracts');

/*
 |==============================================================================
 | Phase UI-04 — generated-consumer parity (ADR-021).
 |
 | The token authority is only a single source of truth if its consumers are actually derived
 | from it. This suite proves the two generated artifacts are byte-current with the source and
 | carry every token, so a hand-edit or a forgotten regeneration fails the build rather than
 | shipping a stale colour.
 */

function ui04GeneratedCss(): string
{
    return (string) file_get_contents(base_path('resources/spa/src/styles/generated/tokens.css'));
}

function ui04GeneratedTs(): string
{
    return (string) file_get_contents(base_path('resources/spa/src/design-system/tokens.generated.ts'));
}

it('records the source hash in both generated artifacts', function (): void {
    $sourceHash = hash_file('sha256', base_path('resources/spa/src/design-system/tokens.json'));

    expect(ui04GeneratedCss())->toContain($sourceHash);
    expect(ui04GeneratedTs())->toContain($sourceHash);
});

it('emits a CSS custom property for every semantic token, in both themes', function (): void {
    $css = ui04GeneratedCss();

    // The light block is :root; the dark block is :root.dark. Split so a token cannot pass by
    // appearing only once.
    $darkStart = strpos($css, ':root.dark');
    expect($darkStart)->not->toBeFalse('the generated CSS has no :root.dark block');

    $light = substr($css, 0, (int) $darkStart);
    $dark = substr($css, (int) $darkStart);

    $problems = [];
    foreach (ui04Tokens()['semantic'] as $token) {
        if (! str_contains($light, "--sv-{$token['name']}:")) {
            $problems[] = "{$token['name']}: missing from the light block";
        }
        if (! str_contains($dark, "--sv-{$token['name']}:")) {
            $problems[] = "{$token['name']}: missing from the dark block";
        }
    }

    expect($problems)->toBe([], implode("\n", $problems));
});

it('emits a CSS custom property for every component and typography token', function (): void {
    $css = ui04GeneratedCss();
    $tokens = ui04Tokens();
    $problems = [];

    foreach ($tokens['component'] as $token) {
        if (! str_contains($css, "--sv-{$token['name']}:")) {
            $problems[] = "component {$token['name']}";
        }
    }
    foreach ($tokens['typography']['families'] as $family) {
        if (! str_contains($css, "--sv-{$family['name']}:")) {
            $problems[] = "typography {$family['name']}";
        }
    }
    foreach ($tokens['typography']['scale'] as $step) {
        if (! str_contains($css, "--sv-{$step['name']}:")) {
            $problems[] = "typography {$step['name']}";
        }
    }
    foreach ($tokens['typography']['weights'] as $weight) {
        if (! str_contains($css, "--sv-{$weight['name']}:")) {
            $problems[] = "typography {$weight['name']}";
        }
    }

    expect($problems)->toBe([], 'missing generated tokens: '.implode(', ', $problems));
});

it('emits every legacy alias as a var() reference, never as a duplicated literal', function (): void {
    $css = ui04GeneratedCss();
    $problems = [];

    foreach (ui04Tokens()['legacy_aliases']['map'] as $alias => $target) {
        $expected = "{$alias}: var(--sv-{$target});";
        if (! str_contains($css, $expected)) {
            $problems[] = $expected;
        }
    }

    expect($problems)->toBe([], "legacy aliases must resolve through the semantic tokens:\n".implode("\n", $problems));
});

it('never consults prefers-color-scheme anywhere in the generated CSS', function (): void {
    // ADR-021 rule 2 and CLAUDE.md guardrail 15. This is the defect UI01-THEME-001 recorded.
    expect(ui04GeneratedCss())->not->toContain('prefers-color-scheme');
});

it('marks both generated artifacts as generated', function (): void {
    expect(ui04GeneratedCss())->toContain('GENERATED FILE — do not edit by hand');
    expect(ui04GeneratedTs())->toContain('GENERATED FILE — do not edit by hand');
});

it('exports the breakpoint contract from the generated TypeScript', function (): void {
    $ts = ui04GeneratedTs();

    expect($ts)->toContain('mobileMaxPx: 767');
    expect($ts)->toContain('tabletMinPx: 768');
    expect($ts)->toContain('tabletMaxPx: 1024');
    expect($ts)->toContain('desktopMinPx: 1025');
});

it('exports both theme colour maps from the generated TypeScript', function (): void {
    $ts = ui04GeneratedTs();

    expect($ts)->toContain('export const SEMANTIC_COLORS');
    expect($ts)->toContain('light: Object.freeze({');
    expect($ts)->toContain('dark: Object.freeze({');
    expect($ts)->toContain('export const COMPONENT_TOKENS');
    expect($ts)->toContain('export const PALETTE');
});

it('fails when the source changed without regenerating its consumers', function (): void {
    // The PRIMARY, node-free staleness gate: each generated artifact records the SHA-256 of the
    // source it was produced from. Editing tokens.json without regenerating breaks this
    // immediately, in the PHP suite, inside the app container (which has no Node runtime).
    $sourceHash = hash_file('sha256', base_path('resources/spa/src/design-system/tokens.json'));

    // NOTE: Pest's `toContain` is VARIADIC — a second argument is another needle, not a message.
    // The staleness explanation therefore lives in a plain assertion below.
    $stale = [];
    if (! str_contains(ui04GeneratedCss(), "Source SHA-256: {$sourceHash}")) {
        $stale[] = 'resources/spa/src/styles/generated/tokens.css';
    }
    if (! str_contains(ui04GeneratedTs(), "Source SHA-256: {$sourceHash}")) {
        $stale[] = 'resources/spa/src/design-system/tokens.generated.ts';
    }

    expect($stale)->toBe([], sprintf(
        "These artifacts were generated from a DIFFERENT tokens.json.\nRun: node scripts/generate-design-tokens.mjs\n  - %s",
        implode("\n  - ", $stale),
    ));
});

it('proves the generator itself is deterministic when Node is available', function (): void {
    // The SECONDARY gate, which additionally catches a hand-edit to a generated BODY that left the
    // header hash intact. It needs Node, which the PHP image deliberately does not carry, so it is
    // skipped there and always runs in CI's Frontend job (`npm run tokens:check`).
    $node = trim((string) shell_exec('node --version 2>&1'));
    if (! str_starts_with($node, 'v')) {
        test()->markTestSkipped('Node is not available in this image; the Frontend CI job runs `node scripts/generate-design-tokens.mjs --check`.');
    }

    $output = [];
    $exitCode = 0;
    exec('node '.escapeshellarg(base_path('scripts/generate-design-tokens.mjs')).' --check 2>&1', $output, $exitCode);

    expect($exitCode)->toBe(0, "generated design tokens are STALE:\n".implode("\n", $output));
    expect(implode("\n", $output))->toContain('All artifacts up to date.');
});

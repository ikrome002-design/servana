<?php

declare(strict_types=1);

uses()->group('docs', 'ui05', 'contracts', 'generation', 'staleness');

/*
 |==============================================================================
 | Phase UI-05 — staleness and negative controls (UI/UX plan §8.8: "CI must fail when generated
 | content is stale").
 |
 | Two things are proven here, and the second matters as much as the first:
 |
 |   1. the committed artifacts ARE what the generators produce from the committed inputs;
 |   2. the generators genuinely FAIL when an input is broken.
 |
 | (2) is the negative-control harness. Without it, a generator whose validation silently stopped
 | running would keep passing (1) forever, because a check that never fails also never notices.
 |
 | The harness copies the repository's inputs into a temporary directory, breaks exactly one thing,
 | and requires a non-zero exit naming that problem — and it verifies the UNMUTATED copy passes
 | first, so a control can never pass because the sandbox was already broken.
 */

it('finds the committed role content up to date with its sources', function (): void {
    if (! ui05NodeAvailable()) {
        $this->markTestSkipped('Node is not available in this environment.');
    }

    $result = ui05RunNode('scripts/generate-role-content.mjs', '--check');

    expect($result['exitCode'])->toBe(0, "Generated role content is stale:\n{$result['output']}");
    expect($result['output'])->toContain('All artifacts up to date.');
    expect($result['output'])->toContain('40 documents across 8 accounts');
});

it('finds the committed landing images and derivatives up to date', function (): void {
    if (! ui05NodeAvailable()) {
        $this->markTestSkipped('Node is not available in this environment.');
    }

    $result = ui05RunNode('scripts/generate-landing-images.mjs', '--check');

    expect($result['exitCode'])->toBe(0, "Landing-image artifacts are stale:\n{$result['output']}");
    expect($result['output'])->toContain('All artifacts up to date.');
});

it('finds the UI source inventory up to date after the brand quarantine', function (): void {
    if (! ui05NodeAvailable()) {
        $this->markTestSkipped('Node is not available in this environment.');
    }

    $result = ui05RunNode('scripts/generate-ui-source-inventory.mjs', '--check');

    expect($result['exitCode'])->toBe(0, "UI source inventory is stale:\n{$result['output']}");
    expect($result['output'])->toContain('40 role documents');
    expect($result['output'])->toContain('61 landing images');
});

it('proves every generator guard still fires', function (): void {
    if (! ui05NodeAvailable()) {
        $this->markTestSkipped('Node is not available in this environment.');
    }

    $result = ui05RunNode('scripts/ui05-negative-controls.mjs', '--json');

    /** @var array{total: int, failed: int, controls: list<array<string, mixed>>} $report */
    $report = json_decode($result['output'], true, 512, JSON_THROW_ON_ERROR);

    expect($report['total'])->toBe(17);
    expect($report['failed'])->toBe(0, 'Negative controls that stopped firing: '.implode(', ', array_map(
        static fn (array $control): string => (string) $control['id'],
        array_filter($report['controls'], static fn (array $control): bool => $control['passed'] !== true),
    )));

    foreach ($report['controls'] as $control) {
        // Each control must show the sandbox was healthy first and broken only by the mutation.
        expect($control['baseline_exit_code'])->toBe(0, "Control {$control['id']} started from a broken sandbox.");
        expect($control['mutated_exit_code'])->not->toBe(0);
    }

    expect($result['exitCode'])->toBe(0);
})->group('slow');

it('leaves no negative-control mutation behind in the working tree', function (): void {
    $output = [];
    $exitCode = 0;
    exec(
        'git -C '.escapeshellarg(base_path()).' status --porcelain -- docs config public/assets resources/spa/src/content',
        $output,
        $exitCode,
    );

    expect($exitCode)->toBe(0);
    foreach ($output as $line) {
        // A mutation escaping the sandbox would show up as an unexpected modification to an
        // approved source document or an approved asset.
        expect($line)->not->toContain('docs/legal/');
        expect($line)->not->toContain('docs/support/faq/');
        expect($line)->not->toContain('docs/landing_page/');
        expect($line)->not->toContain('public/assets/brand/Logo.svg');
    }
});

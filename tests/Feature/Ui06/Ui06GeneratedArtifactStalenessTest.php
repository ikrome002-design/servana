<?php

declare(strict_types=1);

uses()->group('docs', 'ui06', 'contracts', 'staleness');

/*
 |==============================================================================
 | Phase UI-06 — artifact staleness.
 |
 | `Ui06LandingContractTest` asserts things ABOUT the audit artifacts. That is only worth anything
 | if the artifacts still describe the code, so this suite proves the committed artifacts are what
 | `node scripts/generate-ui06-artifacts.mjs` produces from the committed sources.
 |
 | It runs the generator rather than re-deriving the artifacts in PHP, because a second
 | implementation would be a second thing to keep in step — and the two could then agree with each
 | other while both disagreeing with the application.
 |
 | Node is not present in the PHP image, so this SKIPS there and runs in CI's Frontend job, where
 | the same command is a named step. The skip is honest: the check has an unambiguous home.
 */

/**
 * Node availability, probed the same way the UI-05 staleness suite probes it. A second mechanism
 * would be a second thing to behave differently in the two environments.
 */
function ui06NodeAvailable(): bool
{
    $output = [];
    $exitCode = 0;
    exec('node --version 2>&1', $output, $exitCode);

    return $exitCode === 0;
}

/**
 * @return array{exitCode: int, output: string}
 */
function ui06RunNode(string $script, string ...$arguments): array
{
    $command = implode(' ', array_map('escapeshellarg', ['node', base_path($script), ...$arguments])).' 2>&1';

    $output = [];
    $exitCode = 0;
    exec($command, $output, $exitCode);

    return ['exitCode' => $exitCode, 'output' => implode("\n", $output)];
}

it('finds the committed UI-06 audit artifacts up to date with their sources', function (): void {
    if (! ui06NodeAvailable()) {
        $this->markTestSkipped('Node is not available in this environment; CI runs this in the Frontend job.');
    }

    $result = ui06RunNode('scripts/generate-ui06-artifacts.mjs', '--check');

    expect($result['exitCode'])->toBe(0, "UI-06 audit artifacts are stale:\n{$result['output']}");
    expect($result['output'])->toContain('UI-06 audit artifacts are current.');
});

it('commits every artifact the generator declares it writes', function (): void {
    // A generator that quietly stopped emitting one would leave a stale file passing every
    // assertion built on it.
    $generator = (string) file_get_contents(base_path('scripts/generate-ui06-artifacts.mjs'));

    preg_match_all("/emit\('([a-z0-9-]+\.json)'/", $generator, $matches);
    $declared = $matches[1];

    expect($declared)->toHaveCount(9);

    foreach ($declared as $name) {
        expect(is_file(base_path("docs/frontend/audits/ui-06/{$name}")))->toBeTrue($name);
    }
});

it('derives the artifacts from the modules rather than from a regular expression', function (): void {
    // An earlier revision parsed the route records out of the source text and reported `auth.login`
    // at `/auth`, every HR child under `/auth/...`, and no `public.legal` at all. Reading a contract
    // by regex is how an artifact ends up describing something the code does not do.
    $generator = (string) file_get_contents(base_path('scripts/generate-ui06-artifacts.mjs'));

    expect($generator)->toContain('ssrLoadModule');
    expect($generator)->toContain('server.ssrLoadModule');
    expect($generator)->not->toContain('pattern.exec(source)');
});

it('records the authorities each artifact was derived from, with their hashes', function (): void {
    foreach ([
        'landing-page-manifest', 'section-parity', 'cta-matrix', 'trust-evidence-matrix',
        'pricing-plan-access-matrix', 'image-render-matrix', 'public-route-matrix',
        'legal-link-matrix', 'faq-route-matrix',
    ] as $name) {
        /** @var array<string, mixed> $artifact */
        $artifact = json_decode(
            (string) file_get_contents(base_path("docs/frontend/audits/ui-06/{$name}.json")),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        expect($artifact['generated_by'])->toBe('node scripts/generate-ui06-artifacts.mjs');
        expect($artifact['phase'])->toBe('UI-06');

        /** @var array<string, array{path: string, sha256: string}> $authorities */
        $authorities = $artifact['authorities'];
        foreach ($authorities as $authority) {
            expect(hash_file('sha256', base_path($authority['path'])))
                ->toBe($authority['sha256'], "{$name}: {$authority['path']} has changed since generation");
        }
    }
});

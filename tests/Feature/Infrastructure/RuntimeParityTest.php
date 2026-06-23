<?php

declare(strict_types=1);

uses()->group('infrastructure', 'parity');

/*
 | Runtime-version parity (Plan §26, §76–77, §79 R7; REM-OPS-001). Docker is the
 | canonical local runtime; no host runtime is canonical. PHP 8.3, Node 20 and
 | Composer 2 must not drift across the app/worker/scheduler image, the SPA/nginx
 | build image, CI, and the machine-readable version metadata. This test fails on
 | any drift.
 */

function repoFile(string $relative): string
{
    return (string) file_get_contents(base_path($relative));
}

it('pins PHP 8.3 across the image, composer platform and CI', function (): void {
    expect(repoFile('docker/php.Dockerfile'))->toContain('php:8.3-fpm-alpine');

    $composer = json_decode(repoFile('composer.json'), true);
    expect($composer['require']['php'])->toBe('^8.3')
        ->and($composer['config']['platform']['php'] ?? '')->toStartWith('8.3.');

    expect(repoFile('.github/workflows/ci.yml'))->toContain("php-version: '8.3'");
});

it('pins Node 20 across the build image, dev tooling, CI and version metadata', function (): void {
    expect(repoFile('docker/nginx.Dockerfile'))->toContain('node:20-alpine')
        ->and(repoFile('docker-compose.yml'))->toContain('node:20-alpine')
        ->and(repoFile('.github/workflows/ci.yml'))->toContain("node-version: '20'")
        ->and(trim(repoFile('.nvmrc')))->toBe('20');

    $package = json_decode(repoFile('package.json'), true);
    expect($package['engines']['node'] ?? '')->toContain('20');
});

it('pins Composer 2 across the image and CI', function (): void {
    expect(repoFile('docker/php.Dockerfile'))->toContain('composer:2')
        ->and(repoFile('.github/workflows/ci.yml'))->toContain('composer:v2');
});

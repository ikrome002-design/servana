<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;

uses()->group('performance', 'opcache', 'docker');

/*
|--------------------------------------------------------------------------
| Phase 24 - production OPcache preload configuration (PH24-OPCACHE-001)
|--------------------------------------------------------------------------
|
| Before Phase 24 the Dockerfile and opcache.ini both CLAIMED "preload in prod"
| while no `opcache.preload` directive existed anywhere. These guards keep the
| configuration, the script and the documentation aligned, so the claim can never
| drift away from the runtime again.
|
| Deliberately configuration-only: no cold-start timing threshold lives here,
| because wall-clock boot time on shared CI hardware is not a reliable assertion
| (benchmark profile §3.1). The measured cold/warm evidence is recorded in the
| Phase 24 results document instead.
|
*/

function p24PreloadScript(): string
{
    return (string) File::get(base_path('docker/php/preload.php'));
}

function p24Dockerfile(): string
{
    return (string) File::get(base_path('docker/php.Dockerfile'));
}

function p24OpcacheIni(): string
{
    return (string) File::get(base_path('docker/php/opcache.ini'));
}

it('ships a preload script', function (): void {
    expect(File::exists(base_path('docker/php/preload.php')))->toBeTrue();
});

it('declares opcache.preload in the shared ini, driven by the build argument', function (): void {
    $ini = p24OpcacheIni();

    expect($ini)->toContain('opcache.preload = ${PHP_OPCACHE_PRELOAD}');
    expect($ini)->toContain('opcache.enable = 1');
});

it('points production at the preload script and leaves development empty', function (): void {
    $dockerfile = p24Dockerfile();

    // Production compiles the app + framework at pool start.
    expect($dockerfile)->toContain('ENV PHP_OPCACHE_PRELOAD=/var/www/html/docker/php/preload.php');

    // Development must NOT preload: the source is bind-mounted and preloaded classes would freeze.
    expect($dockerfile)->toContain('ENV PHP_OPCACHE_PRELOAD=""');

    // The stage-level invariants that make preloading safe.
    expect($dockerfile)->toContain('ENV PHP_OPCACHE_VALIDATE_TIMESTAMPS=0');
    expect($dockerfile)->toContain('--optimize-autoloader');
});

it('keeps the preload script readable by the non-root runtime', function (): void {
    $dockerfile = p24Dockerfile();

    // The whole tree, including docker/php/preload.php, is copied owned by the runtime user, and the
    // prod stage drops to that user before starting php-fpm.
    expect($dockerfile)->toContain('COPY --chown=servana:servana . /var/www/html');
    expect($dockerfile)->toContain('USER servana');
});

it('keeps the preload script inside the build context', function (): void {
    $dockerignore = (string) File::get(base_path('.dockerignore'));
    $lines = array_map('trim', preg_split('/\R/', $dockerignore) ?: []);

    foreach ($lines as $line) {
        if ($line === '' || str_starts_with($line, '#') || str_starts_with($line, '!')) {
            continue;
        }
        expect(str_starts_with('docker/php/preload.php', rtrim($line, '/')))->toBeFalse(
            "`.dockerignore` entry '{$line}' would exclude the preload script from the image.",
        );
    }
});

it('never preloads test, migration, seeder, factory or console-tooling code', function (): void {
    $script = p24PreloadScript();

    foreach (['/tests/', '/database/migrations/', '/database/seeders/', '/database/factories/', '/stubs/'] as $fragment) {
        expect($script)->toContain($fragment);
    }

    // The exclusion list must actually be applied, not merely declared.
    expect($script)->toContain('$excludedFragments');
    expect($script)->toContain('continue 2;');
});

it('compiles rather than executes, so no top-level code runs at pool start', function (): void {
    $script = p24PreloadScript();

    expect($script)->toContain('opcache_compile_file(');

    // The ONLY `require` may be Composer's autoloader; preloading application files via require is
    // what makes a preload script able to fatal the FPM master at boot.
    preg_match_all('/^\s*(?:require|include)(?:_once)?\s+(.+);$/m', $script, $matches);
    $requires = array_map('trim', $matches[1] ?? []);

    expect($requires)->toBe(['$autoload'], 'The preload script must only require the Composer autoloader.');
});

it('embeds no environment value, secret or developer path', function (): void {
    $script = p24PreloadScript();

    foreach (['getenv(', '$_ENV', '$_SERVER[', 'env(', 'putenv('] as $forbidden) {
        expect(str_contains($script, $forbidden))->toBeFalse(
            "The preload script reads `{$forbidden}` — it must embed no environment value.",
        );
    }

    // No absolute developer path: the root is always derived from the script's own location.
    expect($script)->toContain('dirname(__DIR__, 2)');
    expect(preg_match('#[A-Za-z]:\\\\#', $script))->toBe(0, 'A Windows developer path leaked in.');
    expect(str_contains($script, '/home/'))->toBeFalse('A developer home path leaked in.');

    // No connection is opened during preload.
    foreach (['DB::', 'Redis::', 'Cache::', 'file_get_contents(\'http', 'curl_'] as $forbidden) {
        expect(str_contains($script, $forbidden))->toBeFalse(
            "The preload script touches `{$forbidden}` — preloading must not open a connection.",
        );
    }
});

it('builds a deterministic file list', function (): void {
    $script = p24PreloadScript();

    // Same commit ⇒ same list in the same order.
    expect($script)->toContain('sort($files);');
});

it('fails loudly on a broken image rather than degrading silently', function (): void {
    $script = p24PreloadScript();

    expect($script)->toContain('[servana-preload] FATAL');
    expect($script)->toContain('vendor/autoload.php missing');
});

it('uses no CLI-only construct — preload runs under the FPM SAPI', function (): void {
    $script = p24PreloadScript();

    // Regression guard for a real defect found by booting the production image: the script wrote
    // diagnostics with `fwrite(STDERR, …)`, but STDERR/STDOUT/STDIN are defined only for the CLI
    // SAPI. Under php-fpm the preload therefore fataled with "Undefined constant STDERR" and nothing
    // was preloaded — while the pool still came up, so configuration inspection alone looked fine.
    foreach (['STDERR', 'STDOUT', 'STDIN', 'php_sapi_name', 'readline', 'cli_set_process_title'] as $cliOnly) {
        expect(str_contains($script, $cliOnly))->toBeFalse(
            "The preload script references `{$cliOnly}`, which is unavailable under the FPM SAPI that "
            .'actually performs preloading. Use error_log() for diagnostics.',
        );
    }

    expect($script)->toContain('error_log(');
});

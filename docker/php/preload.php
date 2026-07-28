<?php

declare(strict_types=1);

/**
 * Servana production OPcache preload script (Plan §72; Phase 24, PH24-OPCACHE-001).
 *
 * Before Phase 24 the `prod` stage of docker/php.Dockerfile and docker/php/opcache.ini both CLAIMED
 * "preload in prod", but no `opcache.preload` directive existed anywhere — OPcache was enabled and
 * nothing was ever preloaded. This file makes the runtime match the documentation.
 *
 * Design constraints (all deliberate):
 *
 *  - `opcache_compile_file()`, never `require`. Compiling caches the parsed opcodes WITHOUT running
 *    top-level code, so a file with side effects, a conditional class declaration, or an unmet
 *    dependency cannot execute anything or fatal the FPM master at boot. Full `require`-style
 *    preloading links classes more aggressively but is fragile across a 1 300-file application plus
 *    a framework, and a broken preload takes the whole pool down.
 *  - Deterministic: directories are fixed and every file list is sorted, so two builds of the same
 *    commit preload exactly the same files in exactly the same order.
 *  - No environment values, secrets, credentials or tenant data are read or embedded. Nothing here
 *    touches the database, Redis, the network, or the filesystem outside the application image.
 *  - No test, migration, seeder, factory or dev-tooling class is preloaded — none of that exists in
 *    a production request path, and preloading it would waste OPcache memory.
 *  - Fails LOUDLY on a broken image (missing autoloader / missing application root) rather than
 *    silently degrading to no preload.
 *
 * Dev and test are unaffected: `opcache.preload` is only set in the production image.
 */
$root = dirname(__DIR__, 2);

$autoload = $root.'/vendor/autoload.php';

if (! is_file($autoload)) {
    // A production image without an autoloader is broken; say so rather than boot half-configured.
    error_log("[servana-preload] FATAL: vendor/autoload.php missing at {$autoload}");

    return;
}

require $autoload;

/**
 * Directories whose classes are on a production request path.
 *
 * `app/` is Servana's own domain, HTTP and support code. The Illuminate source is the framework
 * itself. Both are stable for the lifetime of an immutable image, which is exactly what preloading
 * assumes — the production image sets `opcache.validate_timestamps=0`, so files never change under
 * a running container.
 *
 * @var list<string>
 */
$directories = [
    $root.'/app',
    $root.'/vendor/laravel/framework/src/Illuminate',
];

/**
 * Path fragments that must never be preloaded.
 *
 * Test/factory/seeder/migration code never runs in production. The framework's `helpers.php` files
 * and stubs are excluded because they are function/template files rather than class files, so they
 * offer nothing to class preloading.
 *
 * @var list<string>
 */
$excludedFragments = [
    '/tests/',
    '/Tests/',
    '/database/migrations/',
    '/database/seeders/',
    '/database/factories/',
    '/stubs/',
    '/Stubs/',
    '/helpers.php',
    '/Illuminate/Foundation/Console/',
    '/Illuminate/Testing/',
    '/Illuminate/Foundation/Testing/',
];

$files = [];

foreach ($directories as $directory) {
    if (! is_dir($directory)) {
        error_log("[servana-preload] FATAL: expected directory missing: {$directory}");

        return;
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
    );

    foreach ($iterator as $file) {
        if (! $file instanceof SplFileInfo || $file->getExtension() !== 'php') {
            continue;
        }

        $path = str_replace('\\', '/', $file->getPathname());

        foreach ($excludedFragments as $fragment) {
            if (str_contains($path, $fragment)) {
                continue 2;
            }
        }

        $files[] = $path;
    }
}

// Deterministic order: the same commit always preloads the same list in the same sequence.
sort($files);

$compiled = 0;
$skipped = 0;

foreach ($files as $path) {
    try {
        if (@opcache_compile_file($path)) {
            $compiled++;
        } else {
            $skipped++;
        }
    } catch (Throwable) {
        // A single uncompilable file must never prevent the pool from starting; it simply is not
        // preloaded and will be compiled on first use exactly as before.
        $skipped++;
    }
}

error_log(sprintf(
    '[servana-preload] compiled %d files, skipped %d, from %d candidates',
    $compiled,
    $skipped,
    count($files),
));

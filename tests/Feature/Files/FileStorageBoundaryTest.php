<?php

declare(strict_types=1);

uses()->group('files', 'security');

/*
 | Storage-boundary guard (Plan §65, §73; Phase 10F). Private business files may be
 | written, promoted or signed ONLY inside the sanctioned file domain
 | (app/Domain/Files). Any other app code that both touches Storage AND performs a
 | write/sign — or issues a temporary signed route/URL — is a boundary violation.
 | Feature phases must call the file-domain service instead of Storage directly.
 */

/** @return list<string> */
function phpSources(): array
{
    // sourceFilesUnder() replaces RecursiveDirectoryIterator, which silently truncated this
    // §65 storage-boundary scan to ~89% of app/ on the dev bind mount (PH23-SCAN-001).
    return sourceFilesUnder(app_path(), ['php']);
}

it('confines private-file writes, promotion and signing to the file domain', function (): void {
    $writeOrSign = ['->put(', '->putFile(', '->putFileAs(', '->writeStream(', '->temporaryUrl(', 'temporarySignedRoute('];
    $sanctioned = str_replace('\\', '/', app_path('Domain/Files')); // file-domain home
    $allowlist = [
        // (none) — infrastructure probes that must write to storage would be listed
        // here with a reason; there are none at Phase 10F.
    ];

    $violations = [];

    foreach (phpSources() as $path) {
        $normalized = str_replace('\\', '/', $path);
        if (str_starts_with($normalized, $sanctioned) || in_array($normalized, $allowlist, true)) {
            continue;
        }

        $contents = (string) file_get_contents($path);
        $touchesStorage = str_contains($contents, 'Storage::') || str_contains($contents, 'Storage\\');
        $signs = str_contains($contents, 'temporarySignedRoute(') || str_contains($contents, '->temporaryUrl(');
        $writes = false;
        foreach ($writeOrSign as $token) {
            if ($token !== 'temporarySignedRoute(' && $token !== '->temporaryUrl(' && str_contains($contents, $token)) {
                $writes = true;
                break;
            }
        }

        if (($touchesStorage && $writes) || $signs) {
            $violations[] = str_replace(str_replace('\\', '/', app_path()).'/', '', $normalized);
        }
    }

    expect($violations)->toBe([]);
});

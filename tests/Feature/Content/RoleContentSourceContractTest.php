<?php

declare(strict_types=1);

uses()->group('docs', 'ui05', 'contracts', 'content');

/*
 |==============================================================================
 | Phase UI-05 — role-content SOURCE contract.
 |
 | The pipeline's first promise is arithmetic: eight accounts, five categories, forty documents,
 | each claimed exactly once, each read from its own account's directory. Everything downstream —
 | verbatim legal text, compiled FAQ, landing regions, curated imagery — is built on that mapping,
 | so a silent duplicate or a cross-mapped key would corrupt every artifact at once while every
 | individual page still looked fine.
 |
 | These assertions are offline and deterministic. They read the committed manifests and the
 | filesystem; they never render a page and never claim one exists.
 */

// The shared UI-05 constants and helpers live in `tests/Pest.php`, not here: `--parallel`
// distributes test FILES across processes, so a symbol defined in one file is undefined in the
// process running another.

it('derives its account keys from the one account-host registry, not a second list', function (): void {
    $manifest = ui05Audit('content-source-manifest');

    expect($manifest['role_key_authority'])->toBe('config/account-hosts.json');

    /** @var array<string, mixed> $registry */
    $registry = json_decode((string) file_get_contents(base_path('config/account-hosts.json')), true, 512, JSON_THROW_ON_ERROR);

    /** @var list<array<string, mixed>> $accounts */
    $accounts = $registry['accounts'];
    $registryKeys = array_map(static fn (array $account): string => (string) $account['account_key'], $accounts);

    /** @var list<array<string, mixed>> $entries */
    $entries = $manifest['entries'];
    $manifestKeys = array_values(array_unique(array_map(
        static fn (array $entry): string => (string) $entry['account_key'],
        $entries,
    )));

    sort($registryKeys);
    sort($manifestKeys);
    expect($manifestKeys)->toBe($registryKeys);

    // Every account's public and legal content key is its own; the pipeline never cross-maps.
    foreach ($accounts as $account) {
        expect($account['public_content_key'])->toBe($account['account_key']);
        expect($account['legal_content_key'])->toBe($account['account_key']);
    }
});

it('accounts for all forty source documents exactly once', function (): void {
    $manifest = ui05Audit('content-source-manifest');

    expect($manifest['accounts'])->toBe(8);
    expect($manifest['categories'])->toBe(5);
    expect($manifest['total_documents'])->toBe(40);
    expect($manifest['duplicate_source_mappings'])->toBe(0);
    expect($manifest['cross_role_mappings'])->toBe(0);

    /** @var list<array<string, mixed>> $entries */
    $entries = $manifest['entries'];
    expect($entries)->toHaveCount(40);

    $pairs = [];
    $paths = [];
    foreach ($entries as $entry) {
        $pairs[] = "{$entry['account_key']}:{$entry['category']}";
        $paths[] = (string) $entry['source_path'];
    }

    expect(array_unique($pairs))->toHaveCount(40, 'An account/category pair is claimed twice.');
    expect(array_unique($paths))->toHaveCount(40, 'A source document is claimed twice.');

    foreach (UI05_ACCOUNTS as $account) {
        foreach (UI05_CATEGORIES as $category) {
            expect($pairs)->toContain("{$account}:{$category}");
        }
    }
});

it('reads every document from its own account directory and records a live hash', function (): void {
    $manifest = ui05Audit('content-source-manifest');

    /** @var list<array<string, mixed>> $entries */
    $entries = $manifest['entries'];
    foreach ($entries as $entry) {
        $relative = (string) $entry['source_path'];
        $directory = UI05_CATEGORY_DIRECTORIES[(string) $entry['category']];

        expect(str_starts_with($relative, $directory.'/'))->toBeTrue(
            "{$relative} is outside the canonical directory {$directory}.",
        );
        expect(basename($relative))->toStartWith((string) $entry['account_key']);

        $absolute = base_path($relative);
        expect(file_exists($absolute))->toBeTrue("Missing source document: {$relative}");

        $bytes = (string) file_get_contents($absolute);
        expect(hash('sha256', $bytes))->toBe($entry['source_sha256'], "Stale hash for {$relative}.");
        expect(strlen($bytes))->toBe($entry['source_bytes']);
    }
});

it('records a reproducible content version and source timestamp', function (): void {
    $manifest = ui05Audit('content-source-manifest');

    expect($manifest['content_version'])->toMatch('/^[0-9a-f]{64}$/');
    expect($manifest['source_timestamp'])->toMatch('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/');

    // The content version is a digest over the sorted (account, category, path, hash) tuples.
    // Recomputing it here proves it is derived from the sources rather than written by hand.
    /** @var list<array<string, mixed>> $entries */
    $entries = $manifest['entries'];
    $rows = array_map(
        static fn (array $entry): string => implode("\t", [
            $entry['account_key'], $entry['category'], $entry['source_path'], $entry['source_sha256'],
        ]),
        $entries,
    );

    expect(hash('sha256', implode("\n", $rows)))->toBe($manifest['content_version']);

    // A build wall clock would sit at or after "now"; a source timestamp cannot.
    expect(strtotime((string) $manifest['source_timestamp']))->toBeLessThan(time());
});

it('finds no raw HTML and no unsafe link in any approved source document', function (): void {
    $manifest = ui05Audit('content-source-manifest');
    expect($manifest['unsafe_raw_html_findings'])->toBe(0);
    expect($manifest['unsafe_link_findings'])->toBe(0);

    $rawHtml = [];
    $unsafeLinks = [];

    foreach (UI05_ACCOUNTS as $account) {
        foreach (UI05_CATEGORY_DIRECTORIES as $category => $directory) {
            $suffix = $category === 'landing' ? '_landing_page_content.md' : "_{$category}.md";
            $relative = "{$directory}/{$account}{$suffix}";
            $text = (string) file_get_contents(base_path($relative));

            if (preg_match('#<\s*/?\s*[a-zA-Z][a-zA-Z0-9-]*(\s[^>]*)?>#', $text) === 1) {
                $rawHtml[] = $relative;
            }
            if (preg_match_all('#\[[^\]]*\]\(([^)]+)\)#', $text, $matches) > 0) {
                foreach ($matches[1] as $href) {
                    if (preg_match('#^(https?://|mailto:|/|\#)#i', trim((string) $href)) !== 1) {
                        $unsafeLinks[] = "{$relative} → {$href}";
                    }
                }
            }
        }
    }

    expect($rawHtml)->toBe([], 'Raw HTML in approved content: '.implode(', ', $rawHtml));
    expect($unsafeLinks)->toBe([], 'Unsafe link targets: '.implode(', ', $unsafeLinks));
});

it('does not leak an absolute workstation path into any generated artifact', function (): void {
    foreach ([
        'content-source-manifest', 'legal-hash-manifest', 'faq-manifest', 'content-parity',
        'logo-manifest', 'derivative-manifest', 'asset-quarantine', 'broken-asset-matrix',
    ] as $name) {
        $text = (string) file_get_contents(base_path(UI05_AUDIT_DIR."/{$name}.json"));

        expect(preg_match('#"[A-Za-z]:\\\\\\\\#', $text))->toBe(0, "{$name}.json leaks a Windows path.");
        expect(preg_match('#"/(home|Users|root)/#', $text))->toBe(0, "{$name}.json leaks a POSIX home path.");
    }
});

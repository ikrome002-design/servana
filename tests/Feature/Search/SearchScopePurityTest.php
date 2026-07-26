<?php

declare(strict_types=1);

use App\Domain\Search\Services\SearchDocumentCatalogue;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;

uses()->group('search', 'phase22', 'security', 'scope-purity');

/*
 |==============================================================================
 | Phase 22 scope purity and the frontend key boundary.
 |
 | Static assertions over the tree: no search-engine credential can reach the SPA,
 | no export-shaped search route exists, the code and the catalogue documentation
 | agree, and Phase 22 has not quietly grown Wallet / R&E / 21N surface.
 |==============================================================================
 */

/** @return list<string> */
function spaSourceFiles(): array
{
    return collect(File::allFiles(base_path('resources/spa/src')))
        ->filter(static fn ($file): bool => in_array($file->getExtension(), ['ts', 'vue', 'js'], true))
        ->map(static fn ($file): string => (string) $file->getRealPath())
        ->values()
        ->all();
}

it('never mentions a Meilisearch host, key or index name anywhere in the SPA source', function (): void {
    $offenders = [];

    foreach (spaSourceFiles() as $path) {
        $contents = (string) file_get_contents($path);

        foreach (['MEILI', 'meilisearch', 'MeiliSearch', ':7700', 'masterKey'] as $token) {
            if (str_contains($contents, $token)) {
                $offenders[] = basename($path).' → '.$token;
            }
        }
    }

    expect($offenders)->toBe([], 'The SPA must never hold a search-engine credential: '.implode(', ', $offenders));
});

it('never publishes a search-engine credential in a Vite-exposed variable', function (): void {
    $envExample = (string) file_get_contents(base_path('.env.example'));

    // Anything prefixed VITE_ is compiled INTO the bundle, so a Meilisearch value there would ship
    // the key to every browser.
    expect($envExample)->not->toContain('VITE_MEILI')
        ->and($envExample)->not->toContain('VITE_SCOUT');
});

it('never publishes a search-engine credential in the generated API contract', function (): void {
    $openapi = (string) file_get_contents(base_path('docs/api/openapi.json'));
    $apiTypes = (string) file_get_contents(base_path('resources/spa/src/types/generated/api.ts'));

    foreach ([$openapi, $apiTypes] as $artifact) {
        expect($artifact)->not->toContain('MEILI')
            ->and($artifact)->not->toContain('meilisearch')
            ->and($artifact)->not->toContain('masterKey')
            ->and($artifact)->not->toContain('phone_encrypted')
            ->and($artifact)->not->toContain('email_encrypted')
            ->and($artifact)->not->toContain('phone_index');
    }
});

it('registers exactly one search route, and it is a read', function (): void {
    $searchRoutes = [];

    foreach (Route::getRoutes() as $route) {
        if (! str_starts_with($route->uri(), 'api/v1')) {
            continue;
        }

        if (preg_match('#(^|/)search(/|$)#', $route->uri()) === 1) {
            $searchRoutes[] = implode('|', $route->methods()).' '.$route->uri();
        }
    }

    expect($searchRoutes)->toBe(['GET|HEAD api/v1/search']);
});

it('exposes no export-shaped route anywhere on the search surface', function (): void {
    $offenders = [];

    foreach (Route::getRoutes() as $route) {
        $uri = strtolower($route->uri());

        if (! str_contains($uri, 'search')) {
            continue;
        }

        foreach (['export', 'download', 'print', 'copy', 'csv', 'xlsx', 'pdf', 'vcard', 'contacts', 'phones'] as $token) {
            if (str_contains($uri, $token)) {
                $offenders[] = $uri;
            }
        }
    }

    expect($offenders)->toBe([]);
});

it('keeps the search domain free of Wallet, provider and R&E reward surface', function (): void {
    $offenders = [];

    foreach (File::allFiles(base_path('app/Domain/Search')) as $file) {
        // Strip comments first: a docblock that says "no Wallet field may be added here" is exactly
        // the documentation this rule wants, so only RUNTIME code is scanned.
        $code = phpCodeWithoutComments((string) file_get_contents((string) $file->getRealPath()));

        foreach (['Daraja', 'Mpesa', 'MPesa', 'STK', 'PayBill', 'C2B', 'Wallet', 'reward_ledger', 're_qualification'] as $token) {
            if (str_contains($code, $token)) {
                $offenders[] = $file->getFilename().' → '.$token;
            }
        }
    }

    expect($offenders)->toBe([], 'Phase 22 must not reach into 20D-W / 21R-B surface: '.implode(', ', $offenders));
});

it('adds no scheduler entry, because scheduled invocation is Phase 21N (D-22-05)', function (): void {
    $consoleRoutes = file_exists(base_path('routes/console.php'))
        ? (string) file_get_contents(base_path('routes/console.php'))
        : '';

    expect($consoleRoutes)->not->toContain('search-verify-counts')
        ->and($consoleRoutes)->not->toContain('search-reindex');
});

/*
 |--------------------------------------------------------------------------
 | Code ⇄ documentation parity
 |--------------------------------------------------------------------------
 */

it('documents every live catalogue type in the search catalogue', function (): void {
    $catalogue = (string) file_get_contents(base_path('docs/architecture/search/search-catalogue.md'));

    foreach (app(SearchDocumentCatalogue::class)->types() as $type) {
        expect($catalogue)->toContain('`'.$type->value.'`');
    }
});

it('ships all three search specification documents', function (string $path): void {
    expect(file_exists(base_path($path)))->toBeTrue($path.' is missing');
})->with([
    'docs/architecture/search/search-catalogue.md',
    'docs/architecture/search/search-indexing.md',
    'docs/architecture/search/search-security.md',
    'docs/proof/phase-22.md',
]);

it('declares index settings for every indexed type, with id-only display and no sortable attribute', function (): void {
    /** @var array<string, array<string, list<string>>> $settings */
    $settings = config('scout.meilisearch.index-settings');

    foreach (app(SearchDocumentCatalogue::class)->indexed() as $definition) {
        $indexName = (string) $definition->indexName();

        expect($settings)->toHaveKey($indexName);

        $index = $settings[$indexName];

        expect($index['filterableAttributes'])->toBe(['merchant_id', 'branch_id'])
            // The engine returns candidate ids only — every displayed value comes from PostgreSQL.
            ->and($index['displayedAttributes'])->toBe(['id'])
            // Empty by design: no caller-supplied token can reach an engine sort expression.
            ->and($index['sortableAttributes'])->toBe([])
            ->and($index['searchableAttributes'])->not->toBeEmpty();

        foreach ($index['searchableAttributes'] as $attribute) {
            expect($attribute)->not->toContain('phone')
                ->and($attribute)->not->toContain('email');
        }
    }
});

it('keeps Scout identify off, so no caller ip or id reaches the engine', function (): void {
    expect(config('scout.identify'))->toBeFalse()
        ->and(config('scout.after_commit'))->toBeTrue()
        ->and(config('scout.soft_delete'))->toBeFalse();
});

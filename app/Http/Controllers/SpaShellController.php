<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Hosts\AccountHost;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Serves the Servana SPA shell (Phase UI-02; closes UI01-PROV-001 and UI01-PROV-002).
 *
 * Before this phase `/` returned Laravel's stock `welcome` scaffold and the only SPA mount
 * lived under `/spa/`, whose HTML requested `/assets/*` — a prefix Laravel owned, so every
 * chunk 404'd and `#app` stayed empty. The shell is now rendered by Laravel at the root of
 * every approved account host, with the entry chunk resolved from the real Vite manifest.
 *
 * Rendering server-side is what lets the account context be TRUSTED: the browser is told
 * which account experience it is on, rather than inferring it from `window.location`
 * (ADR-017). The context carries no identity, tenant, permission or token.
 */
final class SpaShellController extends Controller
{
    /** Vite writes the manifest here for `build.outDir = public/spa`. */
    private const MANIFEST = 'spa/.vite/manifest.json';

    /** The manifest key of the SPA entry, i.e. `resources/spa/index.html`. */
    private const ENTRY = 'index.html';

    /**
     * The two font faces the public hero paints with: the Manrope display title and Inter body copy
     * (Phase UI-17, UI17-PERF-002). Everything else keeps loading on demand behind the
     * metric-compatible fallbacks in `styles/font-fallbacks.css`.
     */
    private const HERO_FONTS = [
        '../../node_modules/@fontsource/manrope/files/manrope-latin-800-normal.woff2',
        '../../node_modules/@fontsource/inter/files/inter-latin-400-normal.woff2',
    ];

    public function __invoke(Request $request, AccountHost $accountHost): Response
    {
        $manifest = $this->manifest();
        $entry = $manifest[self::ENTRY];

        return response()
            ->view('spa', [
                'accountHost' => $accountHost,
                'themePreference' => $this->themePreference($request),
                'entryScript' => '/'.ltrim($entry['file'], '/'),
                'entryStyles' => array_map(
                    static fn (string $css): string => '/'.ltrim($css, '/'),
                    $entry['css'] ?? [],
                ),
                'modulePreloads' => $this->modulePreloads($manifest, $request, $accountHost),
                'fontPreloads' => $this->fontPreloads($manifest, $request),
            ])
            // The HTML shell must REVALIDATE — it names fingerprinted chunks, so a cached
            // shell would keep pointing at a previous deployment's assets. The chunks
            // themselves are immutable and cached hard by Nginx.
            ->header('Cache-Control', 'no-cache, must-revalidate');
    }

    /**
     * The signed-in user's EXPLICIT theme choice, or null (Phase UI-04; ADR-021 §3, §4).
     *
     * Stamping it into the shell is what applies an authenticated preference *before the
     * authenticated shell becomes visible* — no request, no flash, and no client-side rule that
     * could drift from the server's answer.
     *
     * Three deliberate properties:
     *  - Only an EXPLICIT choice is emitted. A user who has never chosen produces null, which is
     *    the "no preference ⇒ light" case; the shell then emits no attribute at all rather than
     *    a stored default that would be indistinguishable from a real choice.
     *  - It is read from the session user only. It is never read from a header, a query parameter
     *    or the host, so it cannot be influenced by the request.
     *  - It is a closed enum value, so the rendered attribute can only ever be `light` or `dark`.
     */
    private function themePreference(Request $request): ?string
    {
        $user = $request->user();

        return $user?->theme_preference?->value;
    }

    /**
     * Chunks the browser must fetch before the requested page can paint, as `modulepreload` URLs
     * (Phase UI-17; UI/UX plan §6.5 "preload only the current shell, the current page and critical
     * shared components").
     *
     * UI17-PERF-003: the shell named only the entry chunk, so its static imports (the Vue runtime and
     * the API client) were discovered only after the entry had downloaded and parsed — one full extra
     * round trip on every cold load (0.75 s on the slow-4G profile). Vite's own HTML preloads them;
     * this restores that from the same manifest. On the landing page (`/`) it also preloads the
     * landing chunk and THIS account's composition and copy, which the page otherwise requests one
     * level later. Nothing from another account is ever named: the content key is the server-resolved
     * one, and an unknown key is skipped rather than guessed.
     *
     * @param  array<string, array{file: string, css?: list<string>, imports?: list<string>}>  $manifest
     * @return list<string>
     */
    private function modulePreloads(array $manifest, Request $request, AccountHost $accountHost): array
    {
        $roots = [self::ENTRY];

        if ($request->path() === '/') {
            $roots[] = 'src/pages/public/PublicLandingPage.vue';
            $roots[] = 'src/content/landing/accounts/'.Str::camel($accountHost->publicContentKey).'.ts';
            $roots[] = 'src/content/generated/'.$accountHost->publicContentKey.'/landing.generated.ts';
        }

        $files = [];
        $seen = [];
        $stack = array_values(array_filter($roots, static fn (string $key): bool => isset($manifest[$key])));

        while ($stack !== []) {
            $key = array_pop($stack);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $files[] = $manifest[$key]['file'];
            foreach ($manifest[$key]['imports'] ?? [] as $import) {
                $stack[] = $import;
            }
        }

        $entryFile = $manifest[self::ENTRY]['file'];
        $urls = array_map(
            static fn (string $file): string => '/'.ltrim($file, '/'),
            array_values(array_filter(array_unique($files), static fn (string $file): bool => $file !== $entryFile)),
        );
        sort($urls);

        return $urls;
    }

    /**
     * The hero font faces, preloaded only for the public landing page (UI17-PERF-002): the SPA
     * otherwise discovers a font only after rendering text that uses it, so the swap — and the
     * layout shift and late LCP repaint that came with it — landed seconds after first paint.
     *
     * @param  array<string, array{file: string}>  $manifest
     * @return list<string>
     */
    private function fontPreloads(array $manifest, Request $request): array
    {
        if ($request->path() !== '/') {
            return [];
        }

        return array_values(array_map(
            static fn (string $key): string => '/'.ltrim($manifest[$key]['file'], '/'),
            array_filter(self::HERO_FONTS, static fn (string $key): bool => isset($manifest[$key]['file'])),
        ));
    }

    /**
     * The Vite manifest, with its entry present.
     *
     * @return array<string, array{file: string, css?: list<string>, imports?: list<string>}>
     */
    private function manifest(): array
    {
        $path = public_path(self::MANIFEST);

        if (! is_file($path)) {
            // Fail loudly rather than rendering a shell with no application in it — a blank
            // `#app` is precisely the defect this phase exists to close.
            throw new RuntimeException(
                'The SPA manifest is missing at public/'.self::MANIFEST.'. Run `npm run build`.',
            );
        }

        /** @var array<string, array{file: string, css?: list<string>, imports?: list<string>}> $manifest */
        $manifest = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);

        if (! isset($manifest[self::ENTRY]['file'])) {
            throw new RuntimeException('The SPA manifest has no "'.self::ENTRY.'" entry.');
        }

        return $manifest;
    }
}

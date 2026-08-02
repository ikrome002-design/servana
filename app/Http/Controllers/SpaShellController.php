<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Hosts\AccountHost;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
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

    public function __invoke(Request $request, AccountHost $accountHost): Response
    {
        $entry = $this->entry();

        return response()
            ->view('spa', [
                'accountHost' => $accountHost,
                'themePreference' => $this->themePreference($request),
                'entryScript' => '/'.ltrim($entry['file'], '/'),
                'entryStyles' => array_map(
                    static fn (string $css): string => '/'.ltrim($css, '/'),
                    $entry['css'] ?? [],
                ),
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
     * The entry chunk and its stylesheets, read from the Vite manifest.
     *
     * @return array{file: string, css?: list<string>}
     */
    private function entry(): array
    {
        $path = public_path(self::MANIFEST);

        if (! is_file($path)) {
            // Fail loudly rather than rendering a shell with no application in it — a blank
            // `#app` is precisely the defect this phase exists to close.
            throw new RuntimeException(
                'The SPA manifest is missing at public/'.self::MANIFEST.'. Run `npm run build`.',
            );
        }

        /** @var array<string, array{file: string, css?: list<string>}> $manifest */
        $manifest = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);

        if (! isset($manifest[self::ENTRY]['file'])) {
            throw new RuntimeException('The SPA manifest has no "'.self::ENTRY.'" entry.');
        }

        return $manifest[self::ENTRY];
    }
}

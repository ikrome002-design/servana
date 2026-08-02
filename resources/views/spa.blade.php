{{--
    Servana SPA shell (Phase UI-02; ADR-016, ADR-017).

    Rendered by Laravel at the root of every approved account host. It names the
    fingerprinted Vite entry from the real manifest and hands the SPA a SERVER-RESOLVED
    account context, so the browser never has to decide its own account identity.

    The context is presentation metadata only: account key, display name, branding keys,
    navigation placement, route family and the default authenticated route. It contains no
    user, tenant, branch, permission, MFA state or token, and it grants nothing — every
    protected request is authorized against the database exactly as before.
--}}
<!doctype html>
{{--
    ADR-021 §3: an authenticated user's EXPLICIT theme choice is stamped here, server-side, so it
    is already in the document when the bootstrap script below runs — the preference is applied
    before any authenticated content becomes visible, with no extra request and no flash.

    The attribute is emitted ONLY when the signed-in user has actually chosen a theme. It is
    absent for an anonymous visitor and for a user who has never chosen, which is exactly the
    "no explicit preference ⇒ light" case. It is presentation state only: it carries no identity,
    no tenant, no permission and grants nothing.
--}}
<html lang="en"@if ($themePreference !== null) data-sv-theme="{{ $themePreference }}"@endif>
  <head>
    <meta charset="UTF-8" />
    {{-- Plan §3 rule 8: never disable zoom. No maximum-scale / user-scalable. --}}
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <link rel="icon" href="/assets/brand/favicon.ico" sizes="any" />
    <link rel="icon" type="image/png" sizes="32x32" href="/assets/brand/favicon-32x32.png" />
    <link rel="icon" type="image/png" sizes="16x16" href="/assets/brand/favicon-16x16.png" />
    <link rel="apple-touch-icon" href="/assets/brand/apple-touch-icon.png" />
    {{--
      Web app manifest (Phase UI-04, closes UI01-ASSET-003). It exists so the two APPROVED
      Android icon files are actually referenced; before this they were present and unused.

      It lives under /assets/ because that prefix is backend-owned in both topologies — nginx
      serves it from Laravel's public root, and the Vite build copies the same tree into the
      preview origin. A manifest at the document root would fall through to this very shell and
      be served as HTML.

      No service worker is registered anywhere in this application and no offline capability is
      claimed; `display: browser` says so honestly.
    --}}
    <link rel="manifest" href="/assets/brand/site.webmanifest" />
    <title>Servana by Citrus</title>
    {{--
        Theme bootstrap. Kept BYTE-IDENTICAL to resources/spa/index.html (asserted by
        AccountHostShellContractTest) so the standalone SPA origin and this shell cannot drift.

        Phase UI-04 closed UI01-THEME-001 here: the script no longer reads the operating-system
        colour-scheme media feature, so a clean browser under a dark OS renders LIGHT, as
        ADR-021 requires. The signed-in user's server preference is stamped onto <html> above
        as `data-sv-theme`, which is what lets the authenticated theme apply before any
        authenticated content is painted — with no request, no flash and no client-side rule.
    --}}
    <script>
      // Servana theme bootstrap (ADR-021; UI/UX plan §12.1-§12.3). Runs before Vue hydration so
      // the correct theme is present at first paint and nothing flashes.
      //
      // LIGHT IS THE DEFAULT. The operating-system colour scheme is never consulted: only an
      // EXPLICIT Servana preference can select dark. That is the whole of UI01-THEME-001.
      //
      // Precedence: the server-rendered preference of a signed-in user, then the explicit
      // per-browser choice, then light. The Laravel shell stamps data-sv-theme for an
      // authenticated user; the standalone SPA origin has no server, so the attribute is simply
      // absent there and this identical script falls through to local storage.
      //
      // This is a fixed literal with no interpolation, and it reads only the theme key.
      try {
        var svRoot = document.documentElement;
        var svTheme = svRoot.getAttribute('data-sv-theme') || localStorage.getItem('servana.theme');
        if (svTheme === 'dark') {
          svRoot.classList.add('dark');
        }
      } catch (e) {
        /* storage unavailable — light, which is the default anyway. */
      }
    </script>
    @foreach ($entryStyles as $style)
      <link rel="stylesheet" href="{{ $style }}" />
    @endforeach
  </head>
  <body>
    <div id="app" data-account-key="{{ $accountHost->accountKey }}"></div>
    <script id="servana-account-context" type="application/json">@json($accountHost->toBootstrapArray())</script>
    <script type="module" src="{{ $entryScript }}"></script>
  </body>
</html>

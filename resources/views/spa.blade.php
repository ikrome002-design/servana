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
<html lang="en">
  <head>
    <meta charset="UTF-8" />
    {{-- Plan §3 rule 8: never disable zoom. No maximum-scale / user-scalable. --}}
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <link rel="icon" href="/assets/brand/favicon.ico" sizes="any" />
    <link rel="icon" type="image/png" sizes="32x32" href="/assets/brand/favicon-32x32.png" />
    <link rel="icon" type="image/png" sizes="16x16" href="/assets/brand/favicon-16x16.png" />
    <link rel="apple-touch-icon" href="/assets/brand/apple-touch-icon.png" />
    <title>Servana by Citrus</title>
    {{--
        Theme bootstrap. Kept BYTE-IDENTICAL to resources/spa/index.html (asserted by
        AccountHostShellContractTest) so the standalone SPA origin and this shell cannot
        drift. Its `prefers-color-scheme` behaviour contradicts ADR-021 (light is the
        default) and is tracked as a UI-01 theme defect owned by UI-04 — UI-02 deliberately
        neither fixes nor worsens it.
    --}}
    <script>
      // Dark-mode flash prevention (Plan §14): set the class before first paint.
      try {
        var stored = localStorage.getItem('servana.theme');
        var prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
        if (stored === 'dark' || (!stored && prefersDark)) {
          document.documentElement.classList.add('dark');
        }
      } catch (e) {
        /* localStorage unavailable — default to light. */
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

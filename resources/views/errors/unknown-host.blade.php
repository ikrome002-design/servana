{{--
    Unknown-host denial (Phase UI-02; ADR-016, ADR-017).

    Returned with 421 Misdirected Request when a browser reaches the application on a host
    that is not an approved Servana account host.

    It deliberately does NOT: name the approved hosts, redirect to a "correct" host, reveal
    whether any account or resource exists, or imply an account context. Redirecting would
    both enumerate the host map and risk silently moving a user toward a broader account
    experience (UI/UX plan §5.4). No assets are referenced, so this page renders correctly
    on an origin that serves nothing else.
--}}
<!doctype html>
<html lang="en">
  <head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>Servana</title>
    <style>
      :root { color-scheme: light; }
      body {
        margin: 0;
        min-height: 100vh;
        display: flex;
        align-items: center;
        justify-content: center;
        padding: 1.5rem;
        background: #fff;
        color: #1b1b18;
        font-family: ui-sans-serif, system-ui, -apple-system, "Segoe UI", sans-serif;
        line-height: 1.6;
      }
      main { max-width: 32rem; }
      h1 { font-size: 1.25rem; margin: 0 0 0.75rem; }
      p { margin: 0; color: #4a4a44; }
    </style>
  </head>
  <body>
    <main data-servana-boundary="unknown-host">
      <h1>This address is not available</h1>
      <p>
        Servana is not served on this address. Please check the web address you used, or
        contact your administrator for the correct one.
      </p>
    </main>
  </body>
</html>

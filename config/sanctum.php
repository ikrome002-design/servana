<?php

declare(strict_types=1);

use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Laravel\Sanctum\Http\Middleware\AuthenticateSession;

/*
|--------------------------------------------------------------------------
| Stateful domains, derived from the canonical account-host registry
|--------------------------------------------------------------------------
|
| Phase UI-03. Servana serves EIGHT account hosts across three environments
| (ADR-016), and every one of them runs the same SPA against the same
| /api/v1 surface — so every one of them must be stateful, or sign-in works
| on `servana.ke` and silently fails on `finance.servana.ke`.
|
| The list is DERIVED from `config/account_hosts.php`, which is itself
| derived from the single authority `config/account-hosts.json`. Requiring
| an operator to maintain a parallel 24-host env string would guarantee
| drift, and a drifted stateful list fails in the most confusing possible
| way (authenticated everywhere except one subdomain).
|
| `require` rather than `config()`: config files are loaded independently
| and in no guaranteed order, so reading the file directly is the only
| deterministic option. It is also cache-safe — `config:cache` stores the
| RESULT of this file, exactly as it already does for account_hosts.php.
|
| It stays fully overridable: setting SANCTUM_STATEFUL_DOMAINS replaces the
| derived list outright. There is deliberately NO wildcard, in any form.
*/
$accountHosts = require __DIR__.'/account_hosts.php';

/** @var list<string> $derivedStateful */
$derivedStateful = [];

foreach ($accountHosts['accounts'] as $account) {
    foreach ($account['hosts'] as $host) {
        $derivedStateful[] = $host;
    }
}

// Local and staging development is published on a container port, and Sanctum matches
// host:port exactly — so the ported form must be listed alongside the bare one.
$localPort = $accountHosts['url']['local_port'] ?? null;

if ($localPort !== null && $localPort !== '' && (int) $localPort !== 0) {
    foreach ($derivedStateful as $host) {
        $derivedStateful[] = $host.':'.(int) $localPort;
    }
}

// The Vite dev server origin, and the loopback origins the dev stack is reached on. These are
// development conveniences only; they are not account hosts and grant nothing.
$derivedStateful = array_values(array_unique([
    ...$derivedStateful,
    'localhost',
    'localhost:8080',
    'localhost:5173',
    '127.0.0.1',
    '127.0.0.1:8080',
    '::1',
]));

return [

    /*
    |--------------------------------------------------------------------------
    | Stateful Domains
    |--------------------------------------------------------------------------
    |
    | Requests from the following domains / hosts will receive stateful API
    | authentication cookies. Typically, these should include your local
    | and production domains which access your API via a frontend SPA.
    |
    */

    'stateful' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('SANCTUM_STATEFUL_DOMAINS', implode(',', $derivedStateful))),
    ))),

    /*
    |--------------------------------------------------------------------------
    | Sanctum Guards
    |--------------------------------------------------------------------------
    |
    | This array contains the authentication guards that will be checked when
    | Sanctum is trying to authenticate a request. If none of these guards
    | are able to authenticate the request, Sanctum will use the bearer
    | token that's present on an incoming request for authentication.
    |
    */

    'guard' => ['web'],

    /*
    |--------------------------------------------------------------------------
    | Expiration Minutes
    |--------------------------------------------------------------------------
    |
    | This value controls the number of minutes until an issued token will be
    | considered expired. This will override any values set in the token's
    | "expires_at" attribute, but first-party sessions are not affected.
    |
    */

    'expiration' => null,

    /*
    |--------------------------------------------------------------------------
    | Token Prefix
    |--------------------------------------------------------------------------
    |
    | Sanctum can prefix new tokens in order to take advantage of numerous
    | security scanning initiatives maintained by open source platforms
    | that notify developers if they commit tokens into repositories.
    |
    | See: https://docs.github.com/en/code-security/secret-scanning/about-secret-scanning
    |
    */

    'token_prefix' => env('SANCTUM_TOKEN_PREFIX', ''),

    /*
    |--------------------------------------------------------------------------
    | Sanctum Middleware
    |--------------------------------------------------------------------------
    |
    | When authenticating your first-party SPA with Sanctum you may need to
    | customize some of the middleware Sanctum uses while processing the
    | request. You may change the middleware listed below as required.
    |
    */

    'middleware' => [
        'authenticate_session' => AuthenticateSession::class,
        'encrypt_cookies' => EncryptCookies::class,
        'validate_csrf_token' => ValidateCsrfToken::class,
    ],

];

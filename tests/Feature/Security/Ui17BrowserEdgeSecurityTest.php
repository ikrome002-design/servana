<?php

declare(strict_types=1);

uses()->group('security', 'ui17');

/*
 | Phase UI-17 — browser-edge security owned by the application (UI/UX plan §18.6).
 |
 | UI17-SEC-003: the framework-default CORS policy answered `Access-Control-Allow-Origin: *` on every
 | /api/v1 response and approved every preflight. Servana's SPA is same-origin on every account host,
 | so the approved cross-origin set is EMPTY — including the other seven account hosts, because a
 | host is never authorization (ADR-017).
 |
 | UI17-SEC-004: with SESSION_SECURE_COOKIE unset the session and XSRF cookies followed
 | Request::isSecure(), which behind a TLS-terminating load balancer is false. Production is now
 | Secure by default; an explicit value still wins.
 */

/** Foreign origins a browser could present: an unrelated site, a look-alike, and a sibling account host. */
dataset('foreign origins', [
    'unrelated site' => 'https://attacker.example',
    'look-alike apex' => 'https://servana.ke.attacker.example',
    'sibling account host' => 'https://finance.servana.ke',
    'null origin' => 'null',
]);

it('approves no cross-origin preflight on the API surface', function (string $origin): void {
    $response = $this->call('OPTIONS', '/api/v1/me', server: [
        'HTTP_ORIGIN' => $origin,
        'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST',
        'HTTP_ACCESS_CONTROL_REQUEST_HEADERS' => 'content-type, x-xsrf-token',
    ]);

    expect($response->headers->has('Access-Control-Allow-Origin'))->toBeFalse()
        ->and($response->headers->has('Access-Control-Allow-Credentials'))->toBeFalse()
        ->and($response->headers->has('Access-Control-Allow-Headers'))->toBeFalse();
})->with('foreign origins');

it('exposes no API response to a foreign origin', function (string $origin): void {
    $response = $this->getJson('/api/v1/me', ['Origin' => $origin]);

    // The request itself is still answered by the normal authorization boundary (401 here); CORS
    // only decides whether the browser may READ it, and the answer is no.
    $response->assertUnauthorized();
    expect($response->headers->has('Access-Control-Allow-Origin'))->toBeFalse();
})->with('foreign origins');

it('withholds the CSRF cookie endpoint from foreign origins', function (): void {
    $response = $this->get('/sanctum/csrf-cookie', ['Origin' => 'https://attacker.example']);

    expect($response->headers->has('Access-Control-Allow-Origin'))->toBeFalse();
});

it('pins the approved cross-origin set to empty with no credentialed wildcard', function (): void {
    expect(config('cors.allowed_origins'))->toBe([])
        ->and(config('cors.allowed_origins_patterns'))->toBe([])
        ->and(config('cors.supports_credentials'))->toBeFalse()
        ->and(config('cors.paths'))->toBe(['api/*', 'sanctum/csrf-cookie']);
});

/**
 * Evaluate config/session.php under a controlled environment and return its `secure` value.
 *
 * The config file is re-read with the variables set exactly as a container would see them, then
 * every variable is restored, so no other test observes the change.
 *
 * @param  array<string, string|null>  $environment
 */
if (! function_exists('ui17SessionSecureUnder')) {
    function ui17SessionSecureUnder(array $environment): mixed
    {
        $saved = [];
        foreach ($environment as $name => $value) {
            $saved[$name] = [$_ENV[$name] ?? null, $_SERVER[$name] ?? null, getenv($name)];
            if ($value === null) {
                unset($_ENV[$name], $_SERVER[$name]);
                putenv($name);
            } else {
                $_ENV[$name] = $_SERVER[$name] = $value;
                putenv("{$name}={$value}");
            }
        }

        try {
            /** @var array{secure: mixed} $config */
            $config = require base_path('config/session.php');

            return $config['secure'];
        } finally {
            foreach ($saved as $name => [$env, $server, $put]) {
                if ($env === null) {
                    unset($_ENV[$name]);
                } else {
                    $_ENV[$name] = $env;
                }
                if ($server === null) {
                    unset($_SERVER[$name]);
                } else {
                    $_SERVER[$name] = $server;
                }
                $put === false ? putenv($name) : putenv("{$name}={$put}");
            }
        }
    }
}

it('issues Secure session cookies in production when nothing says otherwise', function (): void {
    expect(ui17SessionSecureUnder(['APP_ENV' => 'production', 'SESSION_SECURE_COOKIE' => null]))->toBeTrue();
});

it('lets an explicit SESSION_SECURE_COOKIE decide', function (): void {
    expect(ui17SessionSecureUnder(['APP_ENV' => 'production', 'SESSION_SECURE_COOKIE' => 'false']))->toBeFalse()
        ->and(ui17SessionSecureUnder(['APP_ENV' => 'local', 'SESSION_SECURE_COOKIE' => 'true']))->toBeTrue();
});

it('keeps local HTTP development usable without the variable', function (): void {
    expect(ui17SessionSecureUnder(['APP_ENV' => 'local', 'SESSION_SECURE_COOKIE' => null]))->toBeFalse();
});

it('pins fail-closed debug and cookie defaults into the production image only', function (): void {
    // UI17-SEC-005: without a mounted .env the shared entrypoint copies .env.example, whose
    // development values rendered stack traces from a production image. The PROD stage pins safe
    // process-environment defaults; the dev stage must not inherit them.
    $dockerfile = (string) file_get_contents(base_path('docker/php.Dockerfile'));
    $stages = preg_split('/^FROM /m', $dockerfile) ?: [];
    $prod = collect($stages)->first(fn (string $stage): bool => str_contains($stage, ' AS prod'));
    $dev = collect($stages)->first(fn (string $stage): bool => str_contains($stage, ' AS dev'));

    expect($prod)->toBeString()
        ->toContain('ENV APP_ENV=production')
        ->toContain('ENV APP_DEBUG=false')
        ->toContain('ENV SESSION_SECURE_COOKIE=true')
        ->and($dev)->toBeString()
        ->not->toContain('APP_DEBUG=false')
        ->not->toContain('SESSION_SECURE_COOKIE=true');
});

it('keeps session cookies HttpOnly and SameSite=Lax', function (): void {
    expect(config('session.http_only'))->toBeTrue()
        ->and(config('session.same_site'))->toBe('lax')
        // Host-only cookies per account host (UI-03): an empty .env value and an unset one both mean
        // "no Domain attribute".
        ->and(config('session.domain'))->toBeEmpty();
});

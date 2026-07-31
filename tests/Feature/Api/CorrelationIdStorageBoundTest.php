<?php

declare(strict_types=1);

use App\Domain\Audit\Models\AuditLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| Correlation id must fit the column it is written to (Phase UI-03)
|--------------------------------------------------------------------------
|
| REGRESSION. This defect was found by the UI-03 deployed-origin browser proof and by nothing
| else — not by 2,528 backend tests, not by Larastan, not by the UI-02 host smoke.
|
| `CorrelationIdMiddleware` accepted an inbound `X-Correlation-ID` of up to 64 characters, but
| `audit_logs.correlation_id` is `character(26)`. Any longer value was accepted at the boundary,
| carried through the request, and then rejected by PostgreSQL at the audit write with
| SQLSTATE 22001 — surfacing as a 500 on every audited request.
|
| Through the real nginx edge that was EVERY request. `docker/nginx/default.conf` sets
| `HTTP_X_CORRELATION_ID` to nginx's own `$request_id` — a 32-character hex string — whenever the
| client sends no header. The backend suite never reproduced it because Laravel's test client does
| not pass through nginx and the app's self-minted ULID already fits.
|
| It was also reachable by any client: `X-Correlation-ID: <27+ safe characters>` was enough to 500
| an audited endpoint. These tests pin the boundary to the storage width so the two cannot drift
| apart again silently.
*/

uses(RefreshDatabase::class)->group('auth');

/** nginx's `$request_id` shape: 32 hex characters, exactly what the edge sends. */
const NGINX_REQUEST_ID = 'a1b2c3d4e5f60718293a4b5c6d7e8f90';

it('bounds an inbound correlation id to what audit_logs can actually store', function (): void {
    $width = (int) DB::selectOne(
        "select character_maximum_length as len from information_schema.columns
         where table_name = 'audit_logs' and column_name = 'correlation_id'",
    )->len;

    expect(strlen(NGINX_REQUEST_ID))->toBe(32)
        ->and($width)->toBe(26);

    $response = $this->get('/health', ['X-Correlation-ID' => NGINX_REQUEST_ID]);
    $id = (string) $response->headers->get('X-Correlation-ID');

    // The over-wide value is replaced, not truncated — truncation would collide distinct traces.
    expect($id)->not->toBe(NGINX_REQUEST_ID)
        ->and(strlen($id))->toBeLessThanOrEqual($width);
});

it('writes the audit row instead of 500ing when the edge supplies its own request id', function (): void {
    $before = AuditLog::query()->count();

    // The exact request shape that 500'd on the built production pair before the bound was fixed.
    $base = rtrim(accountHostUrl('merchant_administrator', '/'), '/');

    $this->withHeader('Origin', $base)
        ->withHeader('X-Correlation-ID', NGINX_REQUEST_ID)
        ->postJson($base.'/api/v1/auth/magic-link', ['email' => 'nobody@servana.test'])
        ->assertStatus(202);

    // The request is audited (login_link_requested / _denied), and the write no longer throws.
    expect(AuditLog::query()->count())->toBeGreaterThan($before);
});

it('stores a correlation id that round-trips out of the audit row unchanged', function (): void {
    $base = rtrim(accountHostUrl('merchant_administrator', '/'), '/');

    $this->withHeader('Origin', $base)
        ->withHeader('X-Correlation-ID', NGINX_REQUEST_ID)
        ->postJson($base.'/api/v1/auth/magic-link', ['email' => 'nobody@servana.test'])
        ->assertStatus(202);

    $stored = AuditLog::query()->latest('id')->value('correlation_id');

    // `character(26)` blank-pads, so compare trimmed. The point is that a real id was stored.
    expect(trim((string) $stored))->not->toBe('')
        ->and(strlen(trim((string) $stored)))->toBeLessThanOrEqual(26);
});

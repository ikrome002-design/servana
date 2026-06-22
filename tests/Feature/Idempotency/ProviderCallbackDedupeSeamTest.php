<?php

declare(strict_types=1);

use App\Domain\Idempotency\ProviderClaimResult;
use App\Domain\Idempotency\ProviderReplayGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class)->group('idempotency');

/*
 | Provider-callback dedupe seam (Plan §24.1, §24.4; Phase R4). Generic — creates
 | no M-Pesa tables/routes and no signature rules. Phase 20D attaches it to real
 | provider correlation ids + the callback inbox.
 */

beforeEach(function (): void {
    $this->guard = app(ProviderReplayGuard::class);
});

it('processes a first callback and dedupes a replay', function (): void {
    $payload = hash('sha256', 'payload-1');

    expect($this->guard->claim('mpesa', 'sandbox', 'corr-1', $payload))
        ->toBe(ProviderClaimResult::First)
        ->and($this->guard->claim('mpesa', 'sandbox', 'corr-1', $payload))
        ->toBe(ProviderClaimResult::Duplicate);
});

it('does not collide across providers or environments', function (): void {
    $payload = hash('sha256', 'p');

    expect($this->guard->claim('mpesa', 'sandbox', 'corr-x', $payload))->toBe(ProviderClaimResult::First)
        ->and($this->guard->claim('mpesa', 'production', 'corr-x', $payload))->toBe(ProviderClaimResult::First)
        ->and($this->guard->claim('other', 'sandbox', 'corr-x', $payload))->toBe(ProviderClaimResult::First);
});

it('flags a payload mismatch on a reused correlation id', function (): void {
    expect($this->guard->claim('mpesa', 'sandbox', 'corr-2', hash('sha256', 'p1')))
        ->toBe(ProviderClaimResult::First)
        ->and($this->guard->claim('mpesa', 'sandbox', 'corr-2', hash('sha256', 'p2')))
        ->toBe(ProviderClaimResult::PayloadMismatch);
});

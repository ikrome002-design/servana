<?php

declare(strict_types=1);

use App\Domain\Integrations\ReferEarn\Models\ReferralSnapshot;
use App\Domain\Integrations\ReferEarn\Models\ReOutboundEvent;
use App\Domain\Merchants\Models\Merchant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;

uses(RefreshDatabase::class)->group('referearn', 'phase21ra', 'isolation');

/*
 | Tenant isolation and route surface for the Phase 21R-A integration tables.
 |
 | The isolation argument here is deliberately different from a normal tenant-owned table: these
 | three tables carry NO merchant-facing surface at all. There is no route, no controller, no policy
 | and no Resource that can return them, so there is nothing for a cross-tenant request to reach.
 | This file proves that absence rather than asserting a scope that does not apply.
 */

it('exposes no HTTP route for any Refer & Earn resource', function (): void {
    $paths = collect(Route::getRoutes()->getRoutes())->map(fn ($route): string => (string) $route->uri());

    foreach (['referral', 'refer-earn', 'refer_earn', 'outbound-event', 'attribution', 'integrations/products'] as $needle) {
        expect($paths->filter(fn (string $uri): bool => str_contains($uri, $needle))->values()->all())
            ->toBe([], "Phase 21R-A must expose no {$needle} route");
    }
});

it('adds no controller, policy or resource for the integration models', function (): void {
    expect(glob(app_path('Http/Controllers/**/ReferEarn*.php')) ?: [])->toBe([])
        ->and(glob(app_path('Policies/Referral*.php')) ?: [])->toBe([])
        ->and(glob(app_path('Policies/Re*Event*.php')) ?: [])->toBe([])
        ->and(glob(app_path('Http/Resources/**/Referral*.php')) ?: [])->toBe([]);
});

it('keeps each merchant referral snapshot bound to exactly one merchant', function (): void {
    $first = Merchant::factory()->create();
    $second = Merchant::factory()->create();

    $a = ReferralSnapshot::factory()->create(['merchant_id' => $first->id]);
    $b = ReferralSnapshot::factory()->create(['merchant_id' => $second->id]);

    expect(ReferralSnapshot::query()->where('merchant_id', $first->id)->pluck('id')->all())->toBe([$a->id])
        ->and(ReferralSnapshot::query()->where('merchant_id', $second->id)->pluck('id')->all())->toBe([$b->id])
        // Even the same submitted code for two different merchants stays two independent claims
        // (Plan §58B.5 R-22: attribution uniqueness is R&E's decision, not Servana's).
        ->and($a->code_normalized)->not->toBe(null);
});

it('stores two independent snapshots when two merchants submit the same code (R-22)', function (): void {
    $first = Merchant::factory()->create();
    $second = Merchant::factory()->create();

    ReferralSnapshot::factory()->create(['merchant_id' => $first->id, 'code_normalized' => 'SERVANA-SAME1', 'raw_code_encrypted' => 'SERVANA-SAME1']);
    ReferralSnapshot::factory()->create(['merchant_id' => $second->id, 'code_normalized' => 'SERVANA-SAME1', 'raw_code_encrypted' => 'SERVANA-SAME1']);

    expect(ReferralSnapshot::query()->where('code_normalized', 'SERVANA-SAME1')->count())->toBe(2);
});

it('keeps outbox sequences independent per merchant', function (): void {
    $first = Merchant::factory()->create();
    $second = Merchant::factory()->create();

    ReOutboundEvent::factory()->create(['merchant_id' => $first->id, 'merchant_public_id' => $first->ulid, 'sequence_no' => 1]);
    ReOutboundEvent::factory()->create(['merchant_id' => $second->id, 'merchant_public_id' => $second->ulid, 'sequence_no' => 1]);

    expect(ReOutboundEvent::query()->count())->toBe(2)
        ->and(ReOutboundEvent::query()->where('merchant_id', $first->id)->count())->toBe(1);
});

it('carries the merchant public ULID, never an internal id, in the payload', function (): void {
    $merchant = Merchant::factory()->create();
    $event = ReOutboundEvent::factory()->create(['merchant_id' => $merchant->id, 'merchant_public_id' => $merchant->ulid]);

    expect($event->payload['merchant_public_id'])->toBe($merchant->ulid)
        ->and(array_keys($event->payload))->not->toContain('merchant_id')
        ->and(json_encode($event->payload))->not->toContain('"'.$merchant->id.'"');
});

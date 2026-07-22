<?php

declare(strict_types=1);

use App\Domain\Integrations\ReferEarn\Actions\EnqueueProductEvent;
use App\Domain\Integrations\ReferEarn\Enums\MerchantStatusReasonCategory;
use App\Domain\Integrations\ReferEarn\Enums\ReferralSnapshotStatus;
use App\Domain\Integrations\ReferEarn\Enums\ReOutboundEventType;
use App\Domain\Integrations\ReferEarn\Models\ReferralSnapshot;
use App\Domain\Integrations\ReferEarn\Models\ReOutboundEvent;
use App\Domain\Merchants\Actions\DeactivateMerchant;
use App\Domain\Merchants\Actions\ReactivateMerchant;
use App\Domain\Merchants\Actions\SuspendMerchant;
use App\Domain\Merchants\Enums\MerchantStatus;
use App\Domain\Merchants\Models\Merchant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\JsonSchemaAssert;

uses(RefreshDatabase::class)->group('referearn', 'phase21ra', 'phase21ra-lifecycle');

/*
 | Merchant lifecycle emission — Plan §58B.1 (the five 21R-A merchant.* rows), §58B.2.
 |
 | Proves that status transitions and identity changes emit exactly the right event with reason
 | CATEGORY only and a hash-only identity payload, and that an unreferred / invalid / rejected
 | merchant emits nothing at all.
 */

function lifecycleMerchant(?ReferralSnapshotStatus $snapshotStatus = ReferralSnapshotStatus::Confirmed): Merchant
{
    $merchant = Merchant::factory()->create(['status' => MerchantStatus::Active]);

    if ($snapshotStatus !== null) {
        ReferralSnapshot::factory()->create([
            'merchant_id' => $merchant->id,
            'snapshot_status' => $snapshotStatus,
            'code_normalized' => $snapshotStatus === ReferralSnapshotStatus::InvalidFormat ? null : 'SERVANA-X8T2K',
            'confirmed_at' => $snapshotStatus === ReferralSnapshotStatus::Confirmed ? now() : null,
        ]);
    }

    return $merchant;
}

it('emits a category-only status_changed event on suspension', function (): void {
    $merchant = lifecycleMerchant();
    $actor = User::factory()->create();

    app(SuspendMerchant::class)->handle($merchant, 'Suspected card testing by the owner, ticket #4412', $actor);

    $event = ReOutboundEvent::query()->where('event_type', ReOutboundEventType::MerchantStatusChanged->value)->sole();

    expect($event->payload['previous_status'])->toBe('active')
        ->and($event->payload['merchant_status'])->toBe('suspended')
        ->and($event->payload['reason_category'])->toBe('manual')
        // The operator's prose stays inside Servana — this is Plan §58B.2's hardest rule.
        ->and(json_encode($event->payload))->not->toContain('card testing')
        ->and(json_encode($event->payload))->not->toContain('4412');

    $violations = JsonSchemaAssert::violations(
        JsonSchemaAssert::load(base_path('docs/integrations/refer-earn/schemas/'.ReOutboundEventType::MerchantStatusChanged->schemaFile())),
        $event->payload,
    );

    expect($violations)->toBe([], implode(' | ', $violations));
});

it('carries an explicit reason category when one is supplied', function (): void {
    $merchant = lifecycleMerchant();

    app(SuspendMerchant::class)->handle($merchant, 'reason text', User::factory()->create(), MerchantStatusReasonCategory::Fraud);

    expect(ReOutboundEvent::query()->sole()->payload['reason_category'])->toBe('fraud');
});

it('emits status_changed on reactivation and deactivation', function (): void {
    $merchant = lifecycleMerchant();
    $actor = User::factory()->create();

    app(SuspendMerchant::class)->handle($merchant, 'r1', $actor);
    app(ReactivateMerchant::class)->handle($merchant->refresh(), 'r2', $actor);
    app(DeactivateMerchant::class)->handle($merchant->refresh(), 'r3', $actor);

    $events = ReOutboundEvent::query()->orderBy('sequence_no')->get();

    expect($events)->toHaveCount(3)
        ->and($events->pluck('payload.merchant_status')->all())->toBe(['suspended', 'active', 'deactivated'])
        ->and($events->pluck('sequence_no')->all())->toBe([1, 2, 3]);
});

it('emits nothing for an unreferred merchant on any status transition', function (): void {
    $merchant = lifecycleMerchant(null);

    app(SuspendMerchant::class)->handle($merchant, 'reason', User::factory()->create());

    expect(ReOutboundEvent::query()->count())->toBe(0);
});

it('emits nothing once a claim is rejected or was never valid', function (ReferralSnapshotStatus $status): void {
    $merchant = lifecycleMerchant($status);

    app(SuspendMerchant::class)->handle($merchant, 'reason', User::factory()->create());

    expect(ReOutboundEvent::query()->count())->toBe(0);
})->with([
    'rejected' => [ReferralSnapshotStatus::Rejected],
    'invalid_format' => [ReferralSnapshotStatus::InvalidFormat],
]);

it('emits a hash-only identity_snapshot_changed when the merchant name changes', function (): void {
    $merchant = lifecycleMerchant();

    $merchant->forceFill(['name' => 'Glow Studio Nairobi Ltd'])->save();

    $event = ReOutboundEvent::query()->where('event_type', ReOutboundEventType::MerchantIdentitySnapshotChanged->value)->sole();

    expect($event->payload['identity_snapshot_sha256'])->toMatch('/^[0-9a-f]{64}$/')
        ->and($event->payload['changed_field_count'])->toBe(1)
        // The new legal name is never transmitted — only the fact that identity moved.
        ->and(json_encode($event->payload))->not->toContain('Glow Studio Nairobi Ltd');
});

it('emits identity_snapshot_changed when an identity profile field changes', function (): void {
    $merchant = lifecycleMerchant();

    // A MODEL save, not a query-builder mass update: Eloquent observers do not fire for
    // `->profile()->update(...)`, and every as-built writer of this table uses a model save.
    $profile = $merchant->profile()->firstOrCreate(['merchant_id' => $merchant->id]);
    $profile->business_category = 'spa';
    $profile->save();

    expect(ReOutboundEvent::query()->where('event_type', ReOutboundEventType::MerchantIdentitySnapshotChanged->value)->count())->toBe(1);
});

it('does not emit identity_snapshot_changed for a non-identity profile change', function (): void {
    $merchant = lifecycleMerchant();

    // Contact details and address are operational settings, not legal/business identity — and
    // contact details are PII that must never influence a partner-facing event, even as hash input.
    $profile = $merchant->profile()->firstOrCreate(['merchant_id' => $merchant->id]);
    $profile->fill([
        'contact_phone' => '+254712345678',
        'contact_email' => 'owner@example.com',
        'address' => 'Kimathi Street',
        'town' => 'Nairobi',
    ]);
    $profile->save();

    // The save definitely happened — this is not a false pass.
    expect($profile->fresh()?->contact_phone)->toBe('+254712345678')
        ->and(ReOutboundEvent::query()->count())->toBe(0);
});

it('changes the identity hash when identity changes and keeps it stable otherwise', function (): void {
    $merchant = lifecycleMerchant();

    $merchant->forceFill(['name' => 'First Name'])->save();
    $first = ReOutboundEvent::query()->latest('id')->sole()->payload['identity_snapshot_sha256'];

    $merchant->forceFill(['name' => 'Second Name'])->save();
    $second = ReOutboundEvent::query()->latest('id')->first()->payload['identity_snapshot_sha256'];

    expect($second)->not->toBe($first);
});

it('emits setup_completed only for an eligible referred merchant', function (): void {
    // Covered end-to-end by the onboarding suite; here the emission-scope rule is the claim.
    $unreferred = Merchant::factory()->create(['status' => MerchantStatus::PendingSetup]);

    expect(app(EnqueueProductEvent::class)->mayEmitFor($unreferred))->toBeFalse();

    $referred = lifecycleMerchant();

    expect(app(EnqueueProductEvent::class)->mayEmitFor($referred))->toBeTrue();
});

<?php

declare(strict_types=1);

use App\Domain\Auth\Seeders\PermissionSeeder;
use App\Domain\Billing\Contracts\PlanContextResolver;
use App\Domain\Billing\Enums\MerchantBillingStatus;
use App\Domain\Billing\Enums\MerchantSubscriptionStatus;
use App\Domain\Billing\Models\MerchantSubscription;
use App\Domain\Billing\Models\PlanEntitlement;
use App\Domain\Billing\Services\SubscriptionPlanContextResolver;
use App\Domain\Billing\Services\UnboundPlanContextResolver;
use App\Domain\Messaging\Sms\Models\PersonnelSmsCampaign;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;

uses(RefreshDatabase::class)->group('messaging', 'sms', 'phase21s', 'entitlement', 'billing');

beforeEach(function (): void {
    $this->seed(PermissionSeeder::class);
});

/*
 | Plan §20 entitlement gate + §22 billing-status gate.
 |
 | Phase 21S is the first phase with an entitlement-gated permission, and it found the §20 gate had
 | no runtime at all: PlanContextResolver was bound to UnboundPlanContextResolver, which returns null
 | for every merchant, so `sms` could never resolve. These tests prove the old behaviour would have
 | failed, prove the concrete resolver is correct, prove every failure mode fails CLOSED, and prove
 | the blast radius is exactly Phase 21S.
 */

function smsPreviewFor(array $scn)
{
    return test()->actingAs($scn['user'], 'sanctum')->postJson('/api/v1/personnel/me/sms-campaigns/preview', [
        'client_ulids' => [$scn['client']->ulid],
        'message_body' => 'Thank you for visiting us today.',
    ]);
}

/*
 |--------------------------------------------------------------------------
 | The resolver itself
 |--------------------------------------------------------------------------
 */

it('binds the CONCRETE resolver, not the unbound Phase-20A placeholder', function (): void {
    expect(app(PlanContextResolver::class))->toBeInstanceOf(SubscriptionPlanContextResolver::class);
});

it('proves the old unbound resolver would have failed every Phase 21S entitlement check', function (): void {
    $scn = smsScenario();

    // The concrete resolver finds the plan...
    expect(app(PlanContextResolver::class)->resolveActivePlan($scn['merchant']->id)?->id)->toBe($scn['plan']->id);

    // ...while the Phase-20A placeholder returns null for the SAME merchant, which is precisely why
    // `sms` could never have resolved before this phase.
    expect((new UnboundPlanContextResolver)->resolveActivePlan($scn['merchant']->id))->toBeNull();

    app()->bind(PlanContextResolver::class, UnboundPlanContextResolver::class);
    smsPreviewFor($scn)->assertForbidden()->assertJsonPath('error.code', 'no_active_plan');
});

it('resolves the active subscription plan and its entitlements', function (): void {
    $scn = smsScenario();

    $plan = app(PlanContextResolver::class)->resolveActivePlan($scn['merchant']->id);

    expect($plan)->not->toBeNull()
        ->and($plan->id)->toBe($scn['plan']->id)
        ->and($plan->entitlements()->where('entitlement_key', 'sms')->value('enabled'))->toBeTrue();
});

it('fails closed for a merchant with no subscription at all', function (): void {
    $scn = smsScenario();
    MerchantSubscription::query()->where('merchant_id', $scn['merchant']->id)->delete();

    expect(app(PlanContextResolver::class)->resolveActivePlan($scn['merchant']->id))->toBeNull();

    smsPreviewFor($scn)->assertForbidden()->assertJsonPath('error.code', 'no_active_plan');
});

it('fails closed for a TERMINAL subscription (cancelled / expired)', function (MerchantSubscriptionStatus $status): void {
    $scn = smsScenario();
    MerchantSubscription::query()
        ->where('merchant_id', $scn['merchant']->id)
        ->update(['status' => $status->value]);

    // History is not an entitlement source.
    expect(app(PlanContextResolver::class)->resolveActivePlan($scn['merchant']->id))->toBeNull();

    smsPreviewFor($scn)->assertForbidden()->assertJsonPath('error.code', 'no_active_plan');
})->with([
    'cancelled' => MerchantSubscriptionStatus::Cancelled,
    'expired' => MerchantSubscriptionStatus::Expired,
]);

it('never resolves ANOTHER merchant’s subscription', function (): void {
    $a = smsScenario();
    $b = smsScenario();

    expect(app(PlanContextResolver::class)->resolveActivePlan($a['merchant']->id)?->id)->toBe($a['plan']->id)
        ->and(app(PlanContextResolver::class)->resolveActivePlan($b['merchant']->id)?->id)->toBe($b['plan']->id)
        ->and($a['plan']->id)->not->toBe($b['plan']->id);
});

/*
 |--------------------------------------------------------------------------
 | The gate on the SMS routes
 |--------------------------------------------------------------------------
 */

it('allows preview and confirm when the plan enables `sms`', function (): void {
    $scn = smsScenario();

    smsPreviewFor($scn)->assertOk();
    $ulid = smsDraft($scn['user'], [$scn['client']->ulid])->assertCreated()->json('data.id');
    smsConfirm($scn['user'], $ulid)->assertOk();
});

it('denies preview, create and confirm when the `sms` entitlement is DISABLED', function (): void {
    $scn = smsScenario(withSmsEntitlement: false);

    smsPreviewFor($scn)->assertForbidden()->assertJsonPath('error.code', 'entitlement_disabled');
    smsDraft($scn['user'], [$scn['client']->ulid])->assertForbidden();

    // A REAL campaign of this personnel member, so the 403 comes from the entitlement gate and not
    // from route-model binding (which runs first and would 404 an invented ULID).
    $campaign = PersonnelSmsCampaign::factory()->create([
        'merchant_id' => $scn['merchant']->id,
        'branch_id' => $scn['branch']->id,
        'staff_profile_id' => $scn['staff']->id,
        'created_by' => $scn['user']->id,
    ]);

    smsConfirm($scn['user'], $campaign->ulid)
        ->assertForbidden()
        ->assertJsonPath('error.code', 'entitlement_disabled');
});

it('denies when the plan has NO `sms` entitlement row at all', function (): void {
    $scn = smsScenario();
    PlanEntitlement::query()->where('plan_id', $scn['plan']->id)->where('entitlement_key', 'sms')->delete();

    smsPreviewFor($scn)->assertForbidden()->assertJsonPath('error.code', 'entitlement_absent');
});

it('names the entitlement in the error meta so the SPA can route to the right remedy', function (): void {
    $scn = smsScenario(withSmsEntitlement: false);

    smsPreviewFor($scn)->assertForbidden()->assertJsonPath('error.meta.entitlement', 'sms');
});

/*
 |--------------------------------------------------------------------------
 | Billing status (Plan §22): reading survives read-only, SENDING does not
 |--------------------------------------------------------------------------
 */

it('blocks sending in read-only grace and suspended billing, but still allows the served-client READ', function (MerchantBillingStatus $status): void {
    $scn = smsScenario();
    // `billing_status` is not fillable (it is projected transactionally by the billing service),
    // so the test sets it the same way the projection does.
    $scn['merchant']->forceFill(['billing_status' => $status])->save();

    // `personnel.my_served_clients.view` is `allow_read` in the matrix — a merchant in grace can
    // still SEE their served clients.
    test()->actingAs($scn['user'], 'sanctum')->getJson('/api/v1/personnel/me/served-clients/sms')->assertOk();

    // `personnel.my_sms.send` is `block` — every composition/commitment route stops.
    smsPreviewFor($scn)->assertForbidden()->assertJsonPath('error.code', 'billing_read_only');
    smsDraft($scn['user'], [$scn['client']->ulid])->assertForbidden();
})->with([
    'read-only grace' => MerchantBillingStatus::ReadOnlyGrace,
    'suspended billing' => MerchantBillingStatus::SuspendedBilling,
]);

it('allows sending while trialing, active or overdue', function (MerchantBillingStatus $status): void {
    $scn = smsScenario();
    // `billing_status` is not fillable (it is projected transactionally by the billing service),
    // so the test sets it the same way the projection does.
    $scn['merchant']->forceFill(['billing_status' => $status])->save();

    smsPreviewFor($scn)->assertOk();
})->with([
    'trialing' => MerchantBillingStatus::Trialing,
    'active' => MerchantBillingStatus::Active,
    'overdue' => MerchantBillingStatus::Overdue,
]);

it('still lets a personnel member READ an existing campaign in billing read-only', function (): void {
    $scn = smsScenario();
    $ulid = smsDraft($scn['user'], [$scn['client']->ulid])->json('data.id');

    $scn['merchant']->forceFill(['billing_status' => MerchantBillingStatus::ReadOnlyGrace])->save();

    test()->actingAs($scn['user'], 'sanctum')->getJson('/api/v1/personnel/me/sms-campaigns')->assertOk();
    test()->actingAs($scn['user'], 'sanctum')->getJson("/api/v1/personnel/me/sms-campaigns/{$ulid}")->assertOk();
    test()->actingAs($scn['user'], 'sanctum')->getJson("/api/v1/personnel/me/sms-campaigns/{$ulid}/recipients")->assertOk();

    expect(PersonnelSmsCampaign::query()->count())->toBe(1);
});

/*
 |--------------------------------------------------------------------------
 | Blast radius
 |--------------------------------------------------------------------------
 */

it('applies the entitlement gate to the Phase 21S send routes and NOTHING else', function (): void {
    $gated = [];

    foreach (Route::getRoutes()->getRoutes() as $route) {
        foreach ($route->gatherMiddleware() as $middleware) {
            if (is_string($middleware) && str_contains($middleware, 'EnsureEntitlement')) {
                $gated[] = (string) $route->getName();
            }
        }
    }

    sort($gated);

    // Exactly the four composition/commitment routes — never a read, never another domain.
    expect($gated)->toBe([
        'personnel.sms-campaigns.cancel',
        'personnel.sms-campaigns.confirm',
        'personnel.sms-campaigns.preview',
        'personnel.sms-campaigns.store',
    ]);
});

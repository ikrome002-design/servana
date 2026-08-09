<?php

declare(strict_types=1);

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Billing\Models\PlatformBillingSettings;
use App\Domain\Billing\Models\PlatformSmsBillingRule;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Symfony\Component\Yaml\Yaml;

uses(RefreshDatabase::class)->group('billing', 'ui08', 'ui08-sms-billing');

/*
 | COR-UI08-001 §9 — platform SMS billing settings API (navigation map §5.4.9, /billing/sms).
 |
 | Super-Admin platform scope. Reads require platform.billing_settings.view; scheduling and
 | withdrawing a rule require platform.billing_settings.update plus MFA and a fresh
 | billing_configuration step-up. NO SMS-specific permission key exists, and this suite proves it.
 */

function ui08PlatformAdmin(): User
{
    $user = User::factory()->create(['is_platform_staff' => true]);
    confirmedTotp($user);

    return $user;
}

function ui08SmsRule(int $unitCostMinor = 100, ?CarbonImmutable $effectiveFrom = null): PlatformSmsBillingRule
{
    return PlatformSmsBillingRule::factory()->create([
        'unit_cost_minor' => $unitCostMinor,
        'effective_from' => $effectiveFrom ?? CarbonImmutable::now()->subMonth(),
    ]);
}

// --- Authorization boundaries ---------------------------------------------------------------

it('lets a super administrator read the current SMS pricing rule', function (): void {
    PlatformBillingSettings::factory()->create(['currency' => 'KES', 'effective_from' => CarbonImmutable::now()->subYear()]);
    ui08SmsRule(250);

    test()->statefulMfa(now()->getTimestamp())->actingAs(ui08PlatformAdmin(), 'sanctum')
        ->getJson('/api/v1/platform/sms-billing-settings')
        ->assertOk()
        ->assertJsonPath('data.current.unit_cost_minor', 250)
        ->assertJsonPath('data.current.state', 'effective')
        ->assertJsonPath('data.currency', 'KES')
        ->assertJsonPath('data.currency_authority', 'platform_billing_settings');
});

it('denies a merchant user every SMS billing route', function (): void {
    $scn = invoiceScenario();

    foreach ([
        '/api/v1/platform/sms-billing-settings',
        '/api/v1/platform/sms-billing-settings/versions',
        '/api/v1/platform/sms-billing-usage',
        '/api/v1/platform/sms-billing-charge-reconciliation',
    ] as $route) {
        test()->statefulMfa(now()->getTimestamp())->actingAs($scn['actor'], 'sanctum')
            ->getJson($route)
            ->assertForbidden();
    }
});

it('denies an unauthenticated caller', function (): void {
    test()->getJson('/api/v1/platform/sms-billing-settings')->assertUnauthorized();
});

it('adds no SMS-specific permission key to the catalogue', function (): void {
    $matrix = Yaml::parseFile(base_path('docs/auth/permission-matrix.yaml'));

    foreach (array_keys($matrix['keys']) as $key) {
        expect(str_contains((string) $key, 'sms_billing') || str_contains((string) $key, 'sms.billing'))
            ->toBeFalse('COR-UI08-001 authorizes no SMS-specific permission key, but found '.$key);
    }
});

// --- Scheduling -----------------------------------------------------------------------------

it('schedules a future rule with fresh step-up and emits the audit event', function (): void {
    $effective = CarbonImmutable::now()->addDays(14);

    test()->statefulMfa(now()->getTimestamp())->actingAs(ui08PlatformAdmin(), 'sanctum')
        ->withHeaders(['Idempotency-Key' => 'idem-'.Str::random(24)])
        ->postJson('/api/v1/platform/sms-billing-settings/versions', [
            'unit_cost_minor' => 300,
            'effective_from' => $effective->toIso8601String(),
            'reason' => 'Provider tariff increase effective next cycle.',
        ])
        ->assertCreated()
        ->assertJsonPath('data.unit_cost_minor', 300)
        ->assertJsonPath('data.state', 'pending');

    expect(PlatformSmsBillingRule::query()->where('unit_cost_minor', 300)->exists())->toBeTrue()
        ->and(AuditLog::query()->where('action', 'platform_sms_billing.rule_scheduled')->exists())->toBeTrue();
});

it('allows the read but refuses a schedule when the step-up is stale', function (): void {
    // NOTE: TestCase::actingAs() seeds a FRESH MFA session by default, so a plain actingAs()
    // proves nothing about step-up. statefulMfa($stale) is the repository idiom for "MFA present,
    // step-up expired" — the same one Phase20APlatformApiTest uses for billing settings.
    $admin = ui08PlatformAdmin();
    $stale = now()->subHours(2)->getTimestamp();
    ui08SmsRule(100);

    // A stale step-up is fine for a read.
    test()->statefulMfa($stale)->actingAs($admin, 'sanctum')
        ->getJson('/api/v1/platform/sms-billing-settings')
        ->assertOk();

    // It is not fine for a pricing mutation.
    test()->statefulMfa($stale)->actingAs($admin, 'sanctum')
        ->postJson('/api/v1/platform/sms-billing-settings/versions', [
            'unit_cost_minor' => 300,
            'effective_from' => CarbonImmutable::now()->addDay()->toIso8601String(),
            'reason' => 'Attempt with a stale step-up.',
        ], ['Idempotency-Key' => 'idem-'.Str::random(24)])
        ->assertForbidden();

    expect(PlatformSmsBillingRule::query()->where('unit_cost_minor', 300)->exists())->toBeFalse();
});

it('refuses to withdraw a scheduled rule when the step-up is stale', function (): void {
    $rule = PlatformSmsBillingRule::factory()->pending()->create();
    $stale = now()->subHours(2)->getTimestamp();

    test()->statefulMfa($stale)->actingAs(ui08PlatformAdmin(), 'sanctum')
        ->postJson("/api/v1/platform/sms-billing-settings/versions/{$rule->ulid}/cancel", [
            'reason' => 'Attempt with a stale step-up.',
        ], ['Idempotency-Key' => 'idem-'.Str::random(24)])
        ->assertForbidden();

    expect($rule->refresh()->cancelled_at)->toBeNull();
});

it('refuses a backdated rule', function (): void {
    test()->statefulMfa(now()->getTimestamp())->actingAs(ui08PlatformAdmin(), 'sanctum')
        ->withHeaders(['Idempotency-Key' => 'idem-'.Str::random(24)])
        ->postJson('/api/v1/platform/sms-billing-settings/versions', [
            'unit_cost_minor' => 300,
            'effective_from' => CarbonImmutable::now()->subDays(3)->toIso8601String(),
            'reason' => 'Backdating attempt.',
        ])
        ->assertStatus(422);
});

it('refuses a rule with no reason', function (): void {
    test()->statefulMfa(now()->getTimestamp())->actingAs(ui08PlatformAdmin(), 'sanctum')
        ->withHeaders(['Idempotency-Key' => 'idem-'.Str::random(24)])
        ->postJson('/api/v1/platform/sms-billing-settings/versions', [
            'unit_cost_minor' => 300,
            'effective_from' => CarbonImmutable::now()->addDay()->toIso8601String(),
        ])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'validation_failed');
});

it('refuses a decimal unit cost — money is integer minor units', function (): void {
    test()->statefulMfa(now()->getTimestamp())->actingAs(ui08PlatformAdmin(), 'sanctum')
        ->withHeaders(['Idempotency-Key' => 'idem-'.Str::random(24)])
        ->postJson('/api/v1/platform/sms-billing-settings/versions', [
            'unit_cost_minor' => 2.75,
            'effective_from' => CarbonImmutable::now()->addDay()->toIso8601String(),
            'reason' => 'A decimal price must be refused.',
        ])
        ->assertStatus(422);
});

it('refuses a second rule at the same effective instant', function (): void {
    $instant = CarbonImmutable::now()->addDays(10)->startOfSecond();
    PlatformSmsBillingRule::factory()->create(['effective_from' => $instant]);

    test()->statefulMfa(now()->getTimestamp())->actingAs(ui08PlatformAdmin(), 'sanctum')
        ->withHeaders(['Idempotency-Key' => 'idem-'.Str::random(24)])
        ->postJson('/api/v1/platform/sms-billing-settings/versions', [
            'unit_cost_minor' => 400,
            'effective_from' => $instant->toIso8601String(),
            'reason' => 'Overlapping effective instant must be refused.',
        ])
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'sms_billing_rule_overlap');
});

// --- Cancellation ---------------------------------------------------------------------------

it('withdraws a scheduled rule and emits the audit event', function (): void {
    $rule = PlatformSmsBillingRule::factory()->pending()->create();

    test()->statefulMfa(now()->getTimestamp())->actingAs(ui08PlatformAdmin(), 'sanctum')
        ->withHeaders(['Idempotency-Key' => 'idem-'.Str::random(24)])
        ->postJson("/api/v1/platform/sms-billing-settings/versions/{$rule->ulid}/cancel", [
            'reason' => 'Scheduled in error; the tariff change was deferred.',
        ])
        ->assertOk()
        ->assertJsonPath('data.state', 'cancelled');

    expect($rule->refresh()->cancelled_at)->not->toBeNull()
        ->and(AuditLog::query()->where('action', 'platform_sms_billing.rule_cancelled')->exists())->toBeTrue();
});

it('refuses to withdraw a rule that has already taken effect', function (): void {
    $rule = ui08SmsRule(150);

    test()->statefulMfa(now()->getTimestamp())->actingAs(ui08PlatformAdmin(), 'sanctum')
        ->withHeaders(['Idempotency-Key' => 'idem-'.Str::random(24)])
        ->postJson("/api/v1/platform/sms-billing-settings/versions/{$rule->ulid}/cancel", [
            'reason' => 'Attempting to withdraw settled pricing.',
        ])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'invalid_state_transition');

    expect($rule->refresh()->cancelled_at)->toBeNull();
});

it('does not enumerate an unknown rule identifier', function (): void {
    test()->statefulMfa(now()->getTimestamp())->actingAs(ui08PlatformAdmin(), 'sanctum')
        ->withHeaders(['Idempotency-Key' => 'idem-'.Str::random(24)])
        ->postJson('/api/v1/platform/sms-billing-settings/versions/'.Str::ulid().'/cancel', ['reason' => 'Unknown identifier.'])
        ->assertNotFound();
});

// --- Cost notice, usage, reconciliation -------------------------------------------------------

it('generates a server-authoritative cost notice with integer arithmetic', function (): void {
    PlatformBillingSettings::factory()->create(['currency' => 'KES', 'effective_from' => CarbonImmutable::now()->subYear()]);
    ui08SmsRule(250);

    test()->statefulMfa(now()->getTimestamp())->actingAs(ui08PlatformAdmin(), 'sanctum')
        ->getJson('/api/v1/platform/sms-billing-settings/cost-notice-preview?recipient_count=40&segment_count=2')
        ->assertOk()
        ->assertJsonPath('data.billable_units', 80)
        ->assertJsonPath('data.amount_minor', 20000)
        ->assertJsonPath('data.currency', 'KES')
        // No tax is configured at launch, so no tax figure is invented.
        ->assertJsonPath('data.tax_basis_points', null)
        ->assertJsonPath('data.disclosed_tax_minor', null);
});

it('discloses a configured tax separately and never folds it into the charge', function (): void {
    PlatformBillingSettings::factory()->create(['currency' => 'KES', 'effective_from' => CarbonImmutable::now()->subYear()]);
    PlatformSmsBillingRule::factory()->create([
        'unit_cost_minor' => 250,
        'tax_basis_points' => 1600,
        'effective_from' => CarbonImmutable::now()->subMonth(),
    ]);

    $response = test()->statefulMfa(now()->getTimestamp())->actingAs(ui08PlatformAdmin(), 'sanctum')
        ->getJson('/api/v1/platform/sms-billing-settings/cost-notice-preview?recipient_count=10&segment_count=1')
        ->assertOk();

    // 10 units x 250 = 2500 ex-tax; 16% disclosed = 400; total 2900. The CHARGE stays 2500.
    expect($response->json('data.amount_minor'))->toBe(2500)
        ->and($response->json('data.disclosed_tax_minor'))->toBe(400)
        ->and($response->json('data.disclosed_total_minor'))->toBe(2900);
});

it('fails closed when no pricing rule exists at all', function (): void {
    // No rule in the series: the preview refuses rather than quoting zero or silently falling back
    // to a value nobody scheduled. `withoutExceptionHandling` makes the fail-closed explicit.
    expect(PlatformSmsBillingRule::query()->count())->toBe(0);

    test()->withoutExceptionHandling()
        ->statefulMfa(now()->getTimestamp())->actingAs(ui08PlatformAdmin(), 'sanctum')
        ->getJson('/api/v1/platform/sms-billing-settings/cost-notice-preview?recipient_count=1&segment_count=1');
})->throws(RuntimeException::class);

it('returns the version series with derived states, newest first', function (): void {
    PlatformBillingSettings::factory()->create(['currency' => 'KES', 'effective_from' => CarbonImmutable::now()->subYear()]);
    PlatformSmsBillingRule::factory()->create(['unit_cost_minor' => 100, 'effective_from' => CarbonImmutable::now()->subMonths(3)]);
    PlatformSmsBillingRule::factory()->create(['unit_cost_minor' => 200, 'effective_from' => CarbonImmutable::now()->subMonth()]);
    PlatformSmsBillingRule::factory()->create(['unit_cost_minor' => 300, 'effective_from' => CarbonImmutable::now()->addMonth()]);

    $response = test()->statefulMfa(now()->getTimestamp())->actingAs(ui08PlatformAdmin(), 'sanctum')
        ->getJson('/api/v1/platform/sms-billing-settings/versions')
        ->assertOk();

    expect($response->json('data.0.state'))->toBe('pending')       // the future rule
        ->and($response->json('data.1.state'))->toBe('effective')  // the one in force
        ->and($response->json('data.2.state'))->toBe('superseded'); // the older one
});

it('serves an empty, truthful usage and reconciliation projection when nothing has been billed', function (): void {
    ui08SmsRule(100);

    test()->statefulMfa(now()->getTimestamp())->actingAs(ui08PlatformAdmin(), 'sanctum')
        ->getJson('/api/v1/platform/sms-billing-usage')
        ->assertOk()
        ->assertJsonCount(0, 'data');

    test()->statefulMfa(now()->getTimestamp())->actingAs(ui08PlatformAdmin(), 'sanctum')
        ->getJson('/api/v1/platform/sms-billing-charge-reconciliation')
        ->assertOk()
        // Thresholds are unconfigured, and the projection says so rather than claiming "ok".
        ->assertJsonPath('data.thresholds.warning_state', 'not_configured')
        ->assertJsonPath('data.thresholds.anomaly_state', 'not_configured');
});

it('never exposes a recipient, phone number or message body on any SMS billing route', function (): void {
    PlatformBillingSettings::factory()->create(['currency' => 'KES', 'effective_from' => CarbonImmutable::now()->subYear()]);
    ui08SmsRule(100);

    foreach ([
        '/api/v1/platform/sms-billing-settings',
        '/api/v1/platform/sms-billing-settings/versions',
        '/api/v1/platform/sms-billing-usage',
        '/api/v1/platform/sms-billing-charge-reconciliation',
    ] as $route) {
        $body = test()->statefulMfa(now()->getTimestamp())->actingAs(ui08PlatformAdmin(), 'sanctum')
            ->getJson($route)
            ->assertOk()
            ->getContent();

        foreach (['phone', 'msisdn', 'recipient_list', 'message_body', 'phone_encrypted', 'phone_last_four'] as $forbidden) {
            expect(str_contains((string) $body, $forbidden))
                ->toBeFalse($route.' leaked a contact field: '.$forbidden);
        }
    }
});

<?php

declare(strict_types=1);

use App\Domain\Auth\Seeders\PermissionSeeder;
use App\Domain\Merchants\Enums\MerchantUserRole;
use App\Domain\Messaging\Sms\Enums\PersonnelSmsCampaignStatus as CampaignStatus;
use App\Domain\Messaging\Sms\Enums\PersonnelSmsRecipientDeliveryStatus as RecipientStatus;
use App\Domain\Messaging\Sms\Enums\SmsBillingEntryStatus as BillingStatus;
use App\Domain\Messaging\Sms\Exceptions\PersonnelSmsStateException;
use App\Domain\Messaging\Sms\Models\PersonnelSmsCampaign;
use App\Domain\Messaging\Sms\Models\PersonnelSmsRecipient;
use App\Domain\Messaging\Sms\Services\PersonnelSmsCampaignStateMachine;
use App\Domain\Messaging\Sms\Services\PersonnelSmsRecipientStateMachine;
use App\Domain\Messaging\Sms\Services\SmsBillingEntryStateMachine;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class)->group('messaging', 'sms', 'phase21s', 'isolation', 'state-machine');

beforeEach(function (): void {
    $this->seed(PermissionSeeder::class);
});

/*
 |--------------------------------------------------------------------------
 | Tenant / branch / personnel isolation
 |--------------------------------------------------------------------------
 */

it('404s another MERCHANT’s campaign — never 403, so existence never leaks', function (): void {
    $mine = smsScenario();
    $theirs = smsScenario();
    $theirUlid = smsDraft($theirs['user'], [$theirs['client']->ulid])->json('data.id');

    test()->actingAs($mine['user'], 'sanctum')
        ->getJson("/api/v1/personnel/me/sms-campaigns/{$theirUlid}")
        ->assertNotFound();

    test()->actingAs($mine['user'], 'sanctum')
        ->getJson("/api/v1/personnel/me/sms-campaigns/{$theirUlid}/recipients")
        ->assertNotFound();
});

it('403s another PERSONNEL member’s campaign in the same merchant', function (): void {
    $scn = smsScenario();
    [$otherUser, , $otherStaff] = branchStaff($scn['merchant'], $scn['branch'], MerchantUserRole::Personnel);
    $otherClient = smsServedClient($scn['merchant'], $scn['branch'], $otherStaff, $scn['service'], phone: '+254744556677');

    $theirUlid = smsDraft($otherUser, [$otherClient->ulid])->json('data.id');

    // Same tenant, so the binding resolves — the POLICY is what denies, and it denies on the
    // staff-profile check, not on the branch.
    test()->actingAs($scn['user'], 'sanctum')
        ->getJson("/api/v1/personnel/me/sms-campaigns/{$theirUlid}")
        ->assertForbidden();

    test()->actingAs($scn['user'], 'sanctum')->postJson(
        "/api/v1/personnel/me/sms-campaigns/{$theirUlid}/confirm",
        ['acknowledged' => true],
        ['Idempotency-Key' => (string) Str::uuid()],
    )->assertForbidden();

    test()->actingAs($scn['user'], 'sanctum')->postJson(
        "/api/v1/personnel/me/sms-campaigns/{$theirUlid}/cancel",
        [],
        ['Idempotency-Key' => (string) Str::uuid()],
    )->assertForbidden();
});

it('lists only the acting personnel member’s own campaigns', function (): void {
    $scn = smsScenario();
    [$otherUser, , $otherStaff] = branchStaff($scn['merchant'], $scn['branch'], MerchantUserRole::Personnel);
    $otherClient = smsServedClient($scn['merchant'], $scn['branch'], $otherStaff, $scn['service'], phone: '+254744667788');

    $mine = smsDraft($scn['user'], [$scn['client']->ulid])->json('data.id');
    $theirs = smsDraft($otherUser, [$otherClient->ulid])->json('data.id');

    $ids = collect(test()->actingAs($scn['user'], 'sanctum')->getJson('/api/v1/personnel/me/sms-campaigns')->json('data'))
        ->pluck('id')->all();

    expect($ids)->toBe([$mine])->not->toContain($theirs);
});

it('cannot message a client of another merchant even by exact ULID', function (): void {
    $mine = smsScenario();
    $theirs = smsScenario();

    smsDraft($mine['user'], [$theirs['client']->ulid])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'no_eligible_recipients');

    expect(PersonnelSmsCampaign::query()->count())->toBe(0);
});

it('cannot message a client this personnel member never served', function (): void {
    $scn = smsScenario();
    [, , $otherStaff] = branchStaff($scn['merchant'], $scn['branch'], MerchantUserRole::Personnel);
    $notMine = smsServedClient($scn['merchant'], $scn['branch'], $otherStaff, $scn['service'], phone: '+254755112233');

    smsDraft($scn['user'], [$notMine->ulid])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'no_eligible_recipients');
});

it('denies every SMS route to a role that is not personnel', function (MerchantUserRole $role): void {
    $scn = smsScenario();
    [$actor] = branchStaff($scn['merchant'], $scn['branch'], $role);

    test()->actingAs($actor, 'sanctum')->getJson('/api/v1/personnel/me/served-clients/sms')->assertForbidden();
    test()->actingAs($actor, 'sanctum')->getJson('/api/v1/personnel/me/sms-campaigns')->assertForbidden();
    test()->actingAs($actor, 'sanctum')->postJson('/api/v1/personnel/me/sms-campaigns/preview', [
        'client_ulids' => [$scn['client']->ulid],
        'message_body' => 'Hello',
    ])->assertForbidden();
})->with([
    'front office' => MerchantUserRole::FrontOffice,
    'hr' => MerchantUserRole::Hr,
    'finance' => MerchantUserRole::Finance,
    'branch manager' => MerchantUserRole::BranchManager,
    'audit' => MerchantUserRole::Audit,
    'merchant admin' => MerchantUserRole::MerchantAdmin,
]);

/*
 |--------------------------------------------------------------------------
 | State machines — every legal transition allowed, everything else 422
 |--------------------------------------------------------------------------
 */

it('authorizes exactly the documented campaign transitions', function (): void {
    $machine = app(PersonnelSmsCampaignStateMachine::class);

    $legal = [
        'draft' => [CampaignStatus::Confirmed, CampaignStatus::Cancelled],
        'confirmed' => [CampaignStatus::Queued, CampaignStatus::Cancelled],
        'queued' => [CampaignStatus::Sending, CampaignStatus::Cancelled],
        'sending' => [CampaignStatus::Completed, CampaignStatus::PartiallyFailed, CampaignStatus::Failed],
        'partially_failed' => [CampaignStatus::Completed, CampaignStatus::Failed],
        'completed' => [],
        'failed' => [],
        'cancelled' => [],
    ];

    foreach ($legal as $from => $allowed) {
        $fromStatus = CampaignStatus::from($from);

        expect($fromStatus->allowedTransitions())->toBe($allowed, "transitions out of {$from}");

        foreach (CampaignStatus::cases() as $to) {
            $expected = in_array($to, $allowed, true);
            expect($machine->canTransition($fromStatus, $to))->toBe($expected, "{$from} -> {$to->value}");

            if (! $expected) {
                expect(fn () => $machine->ensure($fromStatus, $to))
                    ->toThrow(PersonnelSmsStateException::class);
            }
        }
    }
});

it('authorizes exactly the documented recipient transitions', function (): void {
    $machine = app(PersonnelSmsRecipientStateMachine::class);

    $legal = [
        'pending' => [RecipientStatus::Sent, RecipientStatus::Failed, RecipientStatus::OptedOut, RecipientStatus::Suppressed],
        'sent' => [RecipientStatus::Delivered, RecipientStatus::Failed],
        'delivered' => [],
        'failed' => [],
        'opted_out' => [],
        'suppressed' => [],
    ];

    foreach ($legal as $from => $allowed) {
        $fromStatus = RecipientStatus::from($from);
        expect($fromStatus->allowedTransitions())->toBe($allowed, "transitions out of {$from}");

        foreach (RecipientStatus::cases() as $to) {
            expect($machine->canTransition($fromStatus, $to))->toBe(in_array($to, $allowed, true));
        }
    }
});

it('authorizes exactly the documented billing-entry transitions', function (): void {
    $machine = app(SmsBillingEntryStateMachine::class);

    $legal = [
        'provisional' => [BillingStatus::Billable, BillingStatus::Cancelled],
        'billable' => [BillingStatus::Invoiced, BillingStatus::Credited, BillingStatus::Cancelled],
        'invoiced' => [BillingStatus::Credited],
        'credited' => [],
        'cancelled' => [],
    ];

    foreach ($legal as $from => $allowed) {
        $fromStatus = BillingStatus::from($from);
        expect($fromStatus->allowedTransitions())->toBe($allowed, "transitions out of {$from}");

        foreach (BillingStatus::cases() as $to) {
            expect($machine->canTransition($fromStatus, $to))->toBe(in_array($to, $allowed, true));
        }
    }
});

it('rejects an illegal transition at the API with 422 invalid_state_transition', function (): void {
    $scn = smsScenario();
    $ulid = smsDraft($scn['user'], [$scn['client']->ulid])->json('data.id');
    smsConfirm($scn['user'], $ulid);

    // The sync queue settles the campaign; a settled campaign can no longer be cancelled.
    test()->actingAs($scn['user'], 'sanctum')->postJson(
        "/api/v1/personnel/me/sms-campaigns/{$ulid}/cancel",
        [],
        ['Idempotency-Key' => (string) Str::uuid()],
    )->assertStatus(422)->assertJsonPath('error.code', 'invalid_state_transition');
});

/*
 | Each database-level guard gets its OWN test: a failed statement aborts the surrounding
 | PostgreSQL transaction, so two violations in one test would be indistinguishable from one.
 */

it('blocks a terminal campaign status change at the DATABASE', function (): void {
    $scn = smsScenario();
    $ulid = smsDraft($scn['user'], [$scn['client']->ulid])->json('data.id');
    smsConfirm($scn['user'], $ulid);

    $campaign = PersonnelSmsCampaign::query()->where('ulid', $ulid)->firstOrFail();
    expect($campaign->status->isTerminal())->toBeTrue();

    expect(fn () => $campaign->forceFill(['status' => CampaignStatus::Draft])->save())
        ->toThrow(QueryException::class, 'terminal');
});

it('blocks a recipient DELETE at the DATABASE — delivery evidence is never destroyed', function (): void {
    $scn = smsScenario();
    smsDraft($scn['user'], [$scn['client']->ulid]);

    $recipient = PersonnelSmsRecipient::query()->firstOrFail();

    expect(fn () => $recipient->delete())->toThrow(QueryException::class, 'never deleted');
});

it('blocks a recipient snapshot rewrite at the DATABASE', function (): void {
    $scn = smsScenario();
    smsDraft($scn['user'], [$scn['client']->ulid]);

    $recipient = PersonnelSmsRecipient::query()->firstOrFail();

    expect(fn () => $recipient->forceFill(['phone_last_four' => '0000'])->save())
        ->toThrow(QueryException::class, 'immutable');
});

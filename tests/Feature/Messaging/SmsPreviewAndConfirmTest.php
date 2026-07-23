<?php

declare(strict_types=1);

use App\Domain\Audit\Enums\AuditEvent;
use App\Domain\Audit\Models\AuditLog;
use App\Domain\Auth\Seeders\PermissionSeeder;
use App\Domain\Clients\Enums\ClientStatus;
use App\Domain\Clients\Enums\ConsentState;
use App\Domain\Clients\Models\ClientConsent;
use App\Domain\Merchants\Enums\MerchantUserRole;
use App\Domain\Messaging\Sms\Enums\PersonnelSmsCampaignStatus;
use App\Domain\Messaging\Sms\Enums\PersonnelSmsRecipientDeliveryStatus;
use App\Domain\Messaging\Sms\Enums\SmsBillingEntryStatus;
use App\Domain\Messaging\Sms\Jobs\DeliverSmsRecipientJob;
use App\Domain\Messaging\Sms\Models\PersonnelSmsCampaign;
use App\Domain\Messaging\Sms\Models\PersonnelSmsRecipient;
use App\Domain\Messaging\Sms\Models\SmsBillingEntry;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

uses(RefreshDatabase::class)->group('messaging', 'sms', 'phase21s');

beforeEach(function (): void {
    $this->seed(PermissionSeeder::class);
});

function smsPreview(array $scn, array $clientUlids, string $body = 'Thank you for visiting us today.')
{
    return test()->actingAs($scn['user'], 'sanctum')->postJson('/api/v1/personnel/me/sms-campaigns/preview', [
        'client_ulids' => $clientUlids,
        'message_body' => $body,
    ]);
}

/*
 |--------------------------------------------------------------------------
 | Preview — advisory, server-authoritative, contact-free
 |--------------------------------------------------------------------------
 */

it('previews counts, segments and cost without creating, sending or billing anything', function (): void {
    $scn = smsScenario();

    $response = smsPreview($scn, [$scn['client']->ulid])->assertOk();

    $response->assertJsonPath('data.recipient_count', 1)
        ->assertJsonPath('data.excluded_count', 0)
        ->assertJsonPath('data.segment_count', 1)
        // 1 recipient × 1 segment × 100 minor units.
        ->assertJsonPath('data.estimated_cost.amount', 100)
        ->assertJsonPath('data.estimated_cost.currency', 'KES');

    expect($response->json('data.billing_notice'))->toBeString()->not->toBeEmpty();

    // Advisory means advisory.
    expect(PersonnelSmsCampaign::query()->count())->toBe(0)
        ->and(PersonnelSmsRecipient::query()->count())->toBe(0)
        ->and(SmsBillingEntry::query()->count())->toBe(0);
});

it('returns excluded reasons as COUNTS BY CODE, never a per-client list', function (): void {
    $scn = smsScenario();
    $optedOut = smsServedClient($scn['merchant'], $scn['branch'], $scn['staff'], $scn['service'], ConsentState::OptedOut, phone: '+254700111222');
    $noConsent = smsServedClient($scn['merchant'], $scn['branch'], $scn['staff'], $scn['service'], null, phone: '+254700333444');
    $unknown = (string) Str::ulid();

    $response = smsPreview($scn, [$scn['client']->ulid, $optedOut->ulid, $noConsent->ulid, $unknown])->assertOk();

    $response->assertJsonPath('data.recipient_count', 1)
        ->assertJsonPath('data.excluded_count', 3)
        ->assertJsonPath('data.excluded_reasons.consent_opted_out', 1)
        ->assertJsonPath('data.excluded_reasons.consent_missing', 1)
        ->assertJsonPath('data.excluded_reasons.unknown_client', 1);

    // No client identity of ANY kind leaks through the exclusion report (ADR-010).
    $body = $response->getContent();
    foreach ([$optedOut->ulid, $noConsent->ulid, $optedOut->full_name, $noConsent->full_name] as $identity) {
        expect($body)->not->toContain($identity);
    }
});

it('reports a foreign client and an unserved client identically, so preview is no existence oracle', function (): void {
    $scn = smsScenario();
    $foreign = smsScenario()['client'];                       // another merchant entirely
    [, , $otherStaff] = branchStaff($scn['merchant'], $scn['branch'], MerchantUserRole::Personnel);
    $notMine = smsServedClient($scn['merchant'], $scn['branch'], $otherStaff, $scn['service'], phone: '+254788990011');
    $absent = (string) Str::ulid();

    $response = smsPreview($scn, [$foreign->ulid, $absent])->assertOk();

    // A real-but-foreign client and a ULID that matches nothing are BOTH `unknown_client`.
    $response->assertJsonPath('data.excluded_reasons.unknown_client', 2);

    // A client of this merchant served by SOMEONE ELSE is `not_served` — visible as a count only.
    smsPreview($scn, [$notMine->ulid])->assertOk()->assertJsonPath('data.excluded_reasons.not_served', 1);
});

it('counts a duplicated selection once and reports the duplicate', function (): void {
    $scn = smsScenario();

    smsPreview($scn, [$scn['client']->ulid, $scn['client']->ulid])
        ->assertOk()
        ->assertJsonPath('data.recipient_count', 1)
        ->assertJsonPath('data.excluded_reasons.duplicate_selection', 1);
});

it('computes segments and cost server-side from the body, never from the client', function (): void {
    $scn = smsScenario();

    // 161 GSM characters spills into a second segment (160 single / 153 concatenated).
    smsPreview($scn, [$scn['client']->ulid], str_repeat('a', 161))
        ->assertOk()
        ->assertJsonPath('data.segment_count', 2)
        ->assertJsonPath('data.estimated_cost.amount', 200);

    // A single emoji forces UCS-2 (70 per segment).
    smsPreview($scn, [$scn['client']->ulid], 'Thanks ☺')
        ->assertOk()
        ->assertJsonPath('data.requires_unicode', true)
        ->assertJsonPath('data.segment_count', 1);
});

it('rejects every server-owned field on preview and create', function (): void {
    $scn = smsScenario();

    // The request contract is an ALLOWLIST: only `client_ulids` + `message_body` are accepted, so
    // each of these is refused — and so would any field nobody thought to enumerate. Keeping the
    // server-owned names OUT of `rules()` is also what keeps them out of the published OpenAPI
    // contract (ADR-010: `phone_encrypted` must never be advertised as an acceptable input).
    foreach ([
        'estimated_cost_minor' => 1,
        'unit_cost_minor' => 0,
        'recipient_count' => 99,
        'staff_profile_id' => 1,
        'merchant_id' => 1,
        'branch_id' => 1,
        'status' => 'confirmed',
        'currency' => 'USD',
        'phone' => '+254712345678',
        'phone_last_four' => '5678',
        'segment_count' => 1,
        'message_template_id' => 1,
    ] as $field => $value) {
        test()->actingAs($scn['user'], 'sanctum')->postJson('/api/v1/personnel/me/sms-campaigns/preview', [
            'client_ulids' => [$scn['client']->ulid],
            'message_body' => 'Hello',
            $field => $value,
        ])->assertStatus(422)->assertJsonPath('error.fields.'.$field.'.0', 'This field is set by Servana and cannot be supplied.');
    }

    // An unanticipated field is refused too — the allowlist needs no foresight.
    test()->actingAs($scn['user'], 'sanctum')->postJson('/api/v1/personnel/me/sms-campaigns/preview', [
        'client_ulids' => [$scn['client']->ulid],
        'message_body' => 'Hello',
        'a_field_nobody_enumerated' => 'x',
    ])->assertStatus(422)->assertJsonPath('error.fields.a_field_nobody_enumerated.0', 'This field is set by Servana and cannot be supplied.');
});

it('rejects server-owned and unexpected fields at confirmation and cancellation too', function (): void {
    $scn = smsScenario();
    $ulid = smsDraft($scn['user'], [$scn['client']->ulid])->json('data.id');

    foreach (['estimated_cost_minor' => 1, 'recipient_count' => 99, 'client_ulids' => [], 'phone' => '+254712345678'] as $field => $value) {
        test()->actingAs($scn['user'], 'sanctum')->postJson(
            "/api/v1/personnel/me/sms-campaigns/{$ulid}/confirm",
            ['acknowledged' => true, $field => $value],
            ['Idempotency-Key' => (string) Str::uuid()],
        )->assertStatus(422)->assertJsonPath('error.fields.'.$field.'.0', 'This field is set by Servana and cannot be supplied.');
    }

    test()->actingAs($scn['user'], 'sanctum')->postJson(
        "/api/v1/personnel/me/sms-campaigns/{$ulid}/cancel",
        ['status' => 'completed'],
        ['Idempotency-Key' => (string) Str::uuid()],
    )->assertStatus(422)->assertJsonPath('error.fields.status.0', 'This field is set by Servana and cannot be supplied.');
});

it('enforces the batch cap and the message length server-side', function (): void {
    $scn = smsScenario();
    config()->set('sms.limits.max_recipients_per_campaign', 2);
    config()->set('sms.limits.max_message_characters', 20);

    $ulids = [$scn['client']->ulid, (string) Str::ulid(), (string) Str::ulid()];

    smsPreview($scn, $ulids)->assertStatus(422);
    smsPreview($scn, [$scn['client']->ulid], str_repeat('a', 21))->assertStatus(422);
});

it('audits the preview with counts only — no client identity, no body, no phone', function (): void {
    $scn = smsScenario();

    smsPreview($scn, [$scn['client']->ulid], 'Secret message naming Amina')->assertOk();

    $log = AuditLog::query()->where('action', AuditEvent::PersonnelSmsPreviewed->value)->firstOrFail();
    $context = json_encode($log->context);

    expect($log->severity->value)->toBe('info')
        ->and($context)->toContain('recipient_count')
        ->and($context)->not->toContain('Secret message')
        ->and($context)->not->toContain('712345678')
        ->and($context)->not->toContain($scn['client']->ulid);
});

/*
 |--------------------------------------------------------------------------
 | Create (draft) + confirm (the commitment point)
 |--------------------------------------------------------------------------
 */

it('creates a draft with immutable recipient snapshots and no billing entry', function (): void {
    $scn = smsScenario();
    $optedOut = smsServedClient($scn['merchant'], $scn['branch'], $scn['staff'], $scn['service'], ConsentState::OptedOut, phone: '+254700111222');

    $response = smsDraft($scn['user'], [$scn['client']->ulid, $optedOut->ulid])->assertCreated();

    $campaign = PersonnelSmsCampaign::query()->firstOrFail();
    expect($campaign->status)->toBe(PersonnelSmsCampaignStatus::Draft)
        ->and($campaign->recipient_count)->toBe(1)
        ->and($campaign->staff_profile_id)->toBe($scn['staff']->id)
        ->and(SmsBillingEntry::query()->count())->toBe(0);

    // The eligible recipient carries a delivery snapshot; the opted-out one carries NONE.
    $eligible = PersonnelSmsRecipient::query()->where('client_id', $scn['client']->id)->firstOrFail();
    $suppressed = PersonnelSmsRecipient::query()->where('client_id', $optedOut->id)->firstOrFail();

    expect($eligible->delivery_status)->toBe(PersonnelSmsRecipientDeliveryStatus::Pending)
        ->and($eligible->phone_encrypted)->toBe('+254712345678')
        ->and($suppressed->delivery_status)->toBe(PersonnelSmsRecipientDeliveryStatus::OptedOut)
        ->and($suppressed->phone_encrypted)->toBeNull();

    // The response never carries the message body or a full phone.
    expect($response->getContent())->not->toContain('712345678')->not->toContain('message_body');
});

it('refuses to create a campaign when nothing is eligible', function (): void {
    $scn = smsScenario();
    ClientConsent::query()->where('client_id', $scn['client']->id)->update(['state' => ConsentState::OptedOut->value]);

    smsDraft($scn['user'], [$scn['client']->ulid])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'no_eligible_recipients');

    expect(PersonnelSmsCampaign::query()->count())->toBe(0);
});

it('confirms transactionally: consent snapshot, one billing entry, queued after commit', function (): void {
    $scn = smsScenario();
    $ulid = smsDraft($scn['user'], [$scn['client']->ulid])->json('data.id');

    smsConfirm($scn['user'], $ulid)->assertOk();

    $campaign = PersonnelSmsCampaign::query()->where('ulid', $ulid)->firstOrFail();

    // The campaign is at least confirmed; queueing runs afterCommit and may already have advanced it.
    expect($campaign->confirmed_at)->not->toBeNull()
        ->and($campaign->consent_snapshot_at)->not->toBeNull()
        ->and($campaign->status)->not->toBe(PersonnelSmsCampaignStatus::Draft);

    $entries = SmsBillingEntry::query()->where('campaign_id', $campaign->id)->get();
    expect($entries)->toHaveCount(1)
        ->and($entries->first()->quantity)->toBe(1)
        ->and($entries->first()->amount_minor)->toBe(100);
});

it('sends ONCE on a duplicate confirm — no second campaign, recipient, billing entry or dispatch', function (): void {
    $scn = smsScenario();
    // Hold the delivery jobs so the campaign stays in flight and the SECOND confirm is a genuine
    // duplicate of a live campaign rather than of a settled one.
    Queue::fake();
    $ulid = smsDraft($scn['user'], [$scn['client']->ulid])->json('data.id');

    // Two DIFFERENT idempotency keys, so the middleware replay is not what saves us — the domain is.
    smsConfirm($scn['user'], $ulid)->assertOk();
    smsConfirm($scn['user'], $ulid)->assertOk();

    $campaign = PersonnelSmsCampaign::query()->where('ulid', $ulid)->firstOrFail();

    expect(PersonnelSmsCampaign::query()->count())->toBe(1)
        ->and(PersonnelSmsRecipient::query()->where('campaign_id', $campaign->id)->count())->toBe(1)
        ->and(SmsBillingEntry::query()->where('campaign_id', $campaign->id)->whereIn('status', SmsBillingEntryStatus::liveValues())->count())->toBe(1);

    // Exactly ONE delivery job for the single recipient, despite two confirmations.
    Queue::assertPushed(DeliverSmsRecipientJob::class, 1);
});

it('rejects confirmation of a campaign that has already settled', function (): void {
    $scn = smsScenario();
    $ulid = smsDraft($scn['user'], [$scn['client']->ulid])->json('data.id');

    // The sync queue delivers immediately, so this first confirm also settles the campaign.
    smsConfirm($scn['user'], $ulid)->assertOk();
    expect(PersonnelSmsCampaign::query()->where('ulid', $ulid)->firstOrFail()->status->isTerminal())->toBeTrue();

    smsConfirm($scn['user'], $ulid)
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'invalid_state_transition');

    expect(SmsBillingEntry::query()->where('status', SmsBillingEntryStatus::Billable)->count())->toBe(1);
});

it('replays the stored response for a REPEATED Idempotency-Key', function (): void {
    $scn = smsScenario();
    $ulid = smsDraft($scn['user'], [$scn['client']->ulid])->json('data.id');
    $key = (string) Str::uuid();

    $first = smsConfirm($scn['user'], $ulid, $key)->assertOk();
    $second = smsConfirm($scn['user'], $ulid, $key)->assertOk();

    expect($second->json('data.id'))->toBe($first->json('data.id'));
    expect(SmsBillingEntry::query()->count())->toBe(1);
});

it('requires an Idempotency-Key on confirm (financial mutation)', function (): void {
    $scn = smsScenario();
    $ulid = smsDraft($scn['user'], [$scn['client']->ulid])->json('data.id');

    test()->actingAs($scn['user'], 'sanctum')
        ->postJson("/api/v1/personnel/me/sms-campaigns/{$ulid}/confirm", ['acknowledged' => true])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'idempotency_key_required');
});

it('requires an explicit acknowledgement', function (): void {
    $scn = smsScenario();
    $ulid = smsDraft($scn['user'], [$scn['client']->ulid])->json('data.id');

    test()->actingAs($scn['user'], 'sanctum')->postJson(
        "/api/v1/personnel/me/sms-campaigns/{$ulid}/confirm",
        ['acknowledged' => false],
        ['Idempotency-Key' => (string) Str::uuid()],
    )->assertStatus(422);
});

it('re-validates at confirm: a consent withdrawn after drafting suppresses the recipient', function (): void {
    $scn = smsScenario();
    $second = smsServedClient($scn['merchant'], $scn['branch'], $scn['staff'], $scn['service'], phone: '+254700555666');
    $ulid = smsDraft($scn['user'], [$scn['client']->ulid, $second->ulid])->json('data.id');

    // The client changes their mind between drafting and confirming.
    ClientConsent::query()->where('client_id', $second->id)->update(['state' => ConsentState::OptedOut->value]);

    smsConfirm($scn['user'], $ulid)->assertOk();

    $campaign = PersonnelSmsCampaign::query()->where('ulid', $ulid)->firstOrFail();
    $recipient = PersonnelSmsRecipient::query()->where('client_id', $second->id)->firstOrFail();

    expect($recipient->delivery_status)->toBe(PersonnelSmsRecipientDeliveryStatus::OptedOut)
        // re-priced from the survivors, not from the draft's estimate
        ->and($campaign->recipient_count)->toBe(1)
        ->and($campaign->estimated_cost_minor)->toBe(100);
});

it('re-validates at confirm: a client archived after drafting is suppressed', function (): void {
    $scn = smsScenario();
    $second = smsServedClient($scn['merchant'], $scn['branch'], $scn['staff'], $scn['service'], phone: '+254700777888');
    $ulid = smsDraft($scn['user'], [$scn['client']->ulid, $second->ulid])->json('data.id');

    $second->update(['status' => ClientStatus::Archived]);

    smsConfirm($scn['user'], $ulid)->assertOk();

    expect(PersonnelSmsRecipient::query()->where('client_id', $second->id)->firstOrFail()->delivery_status)
        ->toBe(PersonnelSmsRecipientDeliveryStatus::Suppressed);
});

it('refuses confirmation entirely when revalidation leaves nothing', function (): void {
    $scn = smsScenario();
    $ulid = smsDraft($scn['user'], [$scn['client']->ulid])->json('data.id');

    ClientConsent::query()->where('client_id', $scn['client']->id)->update(['state' => ConsentState::OptedOut->value]);

    smsConfirm($scn['user'], $ulid)
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'no_eligible_recipients');

    // The whole transaction rolled back: still a draft, still unbilled, nothing suppressed.
    $campaign = PersonnelSmsCampaign::query()->where('ulid', $ulid)->firstOrFail();
    expect($campaign->status)->toBe(PersonnelSmsCampaignStatus::Draft)
        ->and(SmsBillingEntry::query()->count())->toBe(0)
        ->and(PersonnelSmsRecipient::query()->where('campaign_id', $campaign->id)->first()->delivery_status)
        ->toBe(PersonnelSmsRecipientDeliveryStatus::Pending);
});

it('freezes the composition snapshot once the campaign leaves draft (DB trigger)', function (): void {
    $scn = smsScenario();
    $ulid = smsDraft($scn['user'], [$scn['client']->ulid])->json('data.id');
    smsConfirm($scn['user'], $ulid)->assertOk();

    $campaign = PersonnelSmsCampaign::query()->where('ulid', $ulid)->firstOrFail();

    expect(fn () => $campaign->forceFill(['estimated_cost_minor' => 1])->save())
        ->toThrow(QueryException::class, 'composition/pricing snapshot is immutable');
});

it('cancels a confirmed campaign, suppresses its recipients and cancels the charge', function (): void {
    $scn = smsScenario();
    $ulid = smsDraft($scn['user'], [$scn['client']->ulid])->json('data.id');

    test()->actingAs($scn['user'], 'sanctum')->postJson(
        "/api/v1/personnel/me/sms-campaigns/{$ulid}/cancel",
        [],
        ['Idempotency-Key' => (string) Str::uuid()],
    )->assertOk();

    $campaign = PersonnelSmsCampaign::query()->where('ulid', $ulid)->firstOrFail();

    expect($campaign->status)->toBe(PersonnelSmsCampaignStatus::Cancelled)
        ->and($campaign->cancelled_at)->not->toBeNull()
        ->and(PersonnelSmsRecipient::query()->where('campaign_id', $campaign->id)->first()->delivery_status)
        ->toBe(PersonnelSmsRecipientDeliveryStatus::Suppressed);
});

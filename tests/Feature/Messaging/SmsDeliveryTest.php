<?php

declare(strict_types=1);

use App\Domain\Audit\Enums\AuditEvent;
use App\Domain\Audit\Models\AuditLog;
use App\Domain\Auth\Seeders\PermissionSeeder;
use App\Domain\Messaging\Sms\Actions\DeliverSmsRecipient;
use App\Domain\Messaging\Sms\Actions\RecordSmsDeliveryReceipt;
use App\Domain\Messaging\Sms\Clients\Dto\SmsSendResult;
use App\Domain\Messaging\Sms\Clients\FakeSmsProviderClient;
use App\Domain\Messaging\Sms\Clients\HttpSmsProviderClient;
use App\Domain\Messaging\Sms\Clients\SmsProviderClientInterface;
use App\Domain\Messaging\Sms\Enums\PersonnelSmsCampaignStatus;
use App\Domain\Messaging\Sms\Enums\PersonnelSmsRecipientDeliveryStatus;
use App\Domain\Messaging\Sms\Enums\SmsBillingEntryStatus;
use App\Domain\Messaging\Sms\Enums\SmsDeliveryAttemptStatus;
use App\Domain\Messaging\Sms\Enums\SmsProviderResultClass;
use App\Domain\Messaging\Sms\Exceptions\SmsProviderConfigurationException;
use App\Domain\Messaging\Sms\Jobs\DeliverSmsRecipientJob;
use App\Domain\Messaging\Sms\Models\PersonnelSmsCampaign;
use App\Domain\Messaging\Sms\Models\PersonnelSmsRecipient;
use App\Domain\Messaging\Sms\Models\SmsBillingEntry;
use App\Domain\Messaging\Sms\Models\SmsDeliveryAttempt;
use App\Http\Routing\RouteClass;
use App\Http\Routing\RouteClassification;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;

uses(RefreshDatabase::class)->group('messaging', 'sms', 'phase21s', 'delivery');

beforeEach(function (): void {
    $this->seed(PermissionSeeder::class);
});

/** Drive a campaign to the point where exactly one recipient is pending and queued. */
function smsQueuedCampaign(array $scn): PersonnelSmsCampaign
{
    Queue::fake();
    $ulid = smsDraft($scn['user'], [$scn['client']->ulid])->json('data.id');
    smsConfirm($scn['user'], $ulid);

    return PersonnelSmsCampaign::query()->where('ulid', $ulid)->firstOrFail();
}

/*
 |--------------------------------------------------------------------------
 | Provider binding — CI can never reach a live provider
 |--------------------------------------------------------------------------
 */

it('binds the deterministic FAKE provider in tests, whatever the environment says', function (): void {
    // Even with a fully configured, enabled integration, `testing` short-circuits to the fake.
    config()->set('sms.enabled', true);
    config()->set('sms.base_url', 'https://provider.example');
    config()->set('sms.api_key', 'not-a-real-key');
    config()->set('sms.sender_id', 'SERVANA');
    config()->set('sms.contract_version', '1');

    app()->forgetInstance(SmsProviderClientInterface::class);

    expect(app(SmsProviderClientInterface::class))->toBeInstanceOf(FakeSmsProviderClient::class);
});

it('fails CLOSED in the real HTTP client when any credential is missing', function (string $missing): void {
    config()->set('sms.base_url', 'https://provider.example');
    config()->set('sms.api_key', 'not-a-real-key');
    config()->set('sms.sender_id', 'SERVANA');
    config()->set('sms.contract_version', '1');
    config()->set($missing, null);

    expect(fn () => app(HttpSmsProviderClient::class)->assertConfigured())
        ->toThrow(SmsProviderConfigurationException::class, $missing);
})->with(['sms.base_url', 'sms.api_key', 'sms.sender_id', 'sms.contract_version']);

it('defaults every SMS provider secret to null — nothing is ever guessed', function (): void {
    foreach (['sms.base_url', 'sms.api_key', 'sms.sender_id', 'sms.contract_version'] as $key) {
        expect(config($key))->toBeNull("{$key} must have no default");
    }

    expect(config('sms.enabled'))->toBeFalse();
});

/*
 |--------------------------------------------------------------------------
 | Happy path + roll-up
 |--------------------------------------------------------------------------
 */

it('sends an accepted recipient, records the attempt and settles the campaign', function (): void {
    $scn = smsScenario();
    $campaign = smsQueuedCampaign($scn);
    $recipient = PersonnelSmsRecipient::query()->where('campaign_id', $campaign->id)->firstOrFail();

    app(DeliverSmsRecipient::class)->handle($recipient);

    $recipient->refresh();
    $campaign->refresh();
    $attempt = SmsDeliveryAttempt::query()->where('recipient_id', $recipient->id)->firstOrFail();

    expect($recipient->delivery_status)->toBe(PersonnelSmsRecipientDeliveryStatus::Sent)
        ->and($recipient->provider_message_id)->toStartWith('FAKE-')
        ->and($attempt->attempt_number)->toBe(1)
        ->and($attempt->status)->toBe(SmsDeliveryAttemptStatus::Accepted)
        ->and($attempt->result_class)->toBe(SmsProviderResultClass::Accepted)
        // With no receipt channel (REM-SMS-002), `sent` IS the settled success.
        ->and($campaign->status)->toBe(PersonnelSmsCampaignStatus::Completed)
        ->and($campaign->final_cost_minor)->toBe(100);

    $entry = SmsBillingEntry::query()->where('campaign_id', $campaign->id)->firstOrFail();
    expect($entry->status)->toBe(SmsBillingEntryStatus::Billable)
        ->and($entry->quantity)->toBe(1)
        ->and($entry->amount_minor)->toBe(100);
});

it('hands the provider the right number without ever storing or logging it', function (): void {
    $scn = smsScenario();
    $campaign = smsQueuedCampaign($scn);
    $recipient = PersonnelSmsRecipient::query()->where('campaign_id', $campaign->id)->firstOrFail();

    /** @var FakeSmsProviderClient $fake */
    $fake = app(FakeSmsProviderClient::class);
    app(DeliverSmsRecipient::class)->handle($recipient);

    // Asserted through a DIGEST, so no plaintext number enters a test artefact or failure diff.
    expect($fake->hasSentTo('+254712345678'))->toBeTrue();

    // The correlation reference handed to the provider is NOT the client's identity.
    expect($fake->sentReferences[0]['reference'])
        ->toStartWith('SMS-')
        ->not->toContain($scn['client']->ulid);
});

/*
 |--------------------------------------------------------------------------
 | Retry policy
 |--------------------------------------------------------------------------
 */

it('does NOT retry a permanent failure, and records an opt-out as a consent fact', function (SmsProviderResultClass $class, PersonnelSmsRecipientDeliveryStatus $expected): void {
    $scn = smsScenario();
    $campaign = smsQueuedCampaign($scn);
    $recipient = PersonnelSmsRecipient::query()->where('campaign_id', $campaign->id)->firstOrFail();

    app(FakeSmsProviderClient::class)->queueResult(SmsSendResult::failure($class, $class->value, 'rejected'));
    app(DeliverSmsRecipient::class)->handle($recipient);

    expect($recipient->refresh()->delivery_status)->toBe($expected)
        ->and(SmsDeliveryAttempt::query()->where('recipient_id', $recipient->id)->count())->toBe(1)
        ->and(SmsDeliveryAttempt::query()->where('recipient_id', $recipient->id)->value('next_retry_at'))->toBeNull();

    // Exactly the ONE dispatch that queueing made — no retry was scheduled on top of it.
    Queue::assertPushed(DeliverSmsRecipientJob::class, 1);
})->with([
    'invalid recipient' => [SmsProviderResultClass::InvalidRecipient, PersonnelSmsRecipientDeliveryStatus::Failed],
    'provider opt-out' => [SmsProviderResultClass::OptedOut, PersonnelSmsRecipientDeliveryStatus::OptedOut],
]);

it('retries a transient failure with capped backoff, then dead-letters at the cap', function (): void {
    $scn = smsScenario();
    config()->set('sms.delivery.max_attempts', 2);
    $campaign = smsQueuedCampaign($scn);
    $recipient = PersonnelSmsRecipient::query()->where('campaign_id', $campaign->id)->firstOrFail();

    /** @var FakeSmsProviderClient $fake */
    $fake = app(FakeSmsProviderClient::class);
    $fake->queueResult(
        SmsSendResult::failure(SmsProviderResultClass::ProviderError, 'provider_error', 'upstream down'),
        SmsSendResult::failure(SmsProviderResultClass::ProviderError, 'provider_error', 'upstream down'),
    );

    // Attempt 1: transient, still pending, retry scheduled.
    app(DeliverSmsRecipient::class)->handle($recipient);
    expect($recipient->refresh()->delivery_status)->toBe(PersonnelSmsRecipientDeliveryStatus::Pending);
    $first = SmsDeliveryAttempt::query()->where('recipient_id', $recipient->id)->where('attempt_number', 1)->firstOrFail();
    expect($first->status)->toBe(SmsDeliveryAttemptStatus::TransientFailure)
        ->and($first->next_retry_at)->not->toBeNull();

    // Attempt 2 is the cap: dead-letter.
    app(DeliverSmsRecipient::class)->handle($recipient);
    expect($recipient->refresh()->delivery_status)->toBe(PersonnelSmsRecipientDeliveryStatus::Failed);
    $second = SmsDeliveryAttempt::query()->where('recipient_id', $recipient->id)->where('attempt_number', 2)->firstOrFail();
    expect($second->next_retry_at)->toBeNull();

    expect(AuditLog::query()->where('action', AuditEvent::PersonnelSmsDeliveryDeadLettered->value)->exists())->toBeTrue();
    expect(AuditLog::query()->where('action', AuditEvent::PersonnelSmsDeliveryDeadLettered->value)->first()->severity->value)->toBe('high');

    // No success at all ⇒ the campaign failed, and the provider still consumed the submissions.
    expect($campaign->refresh()->status)->toBe(PersonnelSmsCampaignStatus::Failed);
    expect(SmsBillingEntry::query()->where('campaign_id', $campaign->id)->where('status', SmsBillingEntryStatus::Billable)->count())->toBe(1);
});

it('is a no-op for a recipient that is no longer pending (duplicate dispatch)', function (): void {
    $scn = smsScenario();
    $campaign = smsQueuedCampaign($scn);
    $recipient = PersonnelSmsRecipient::query()->where('campaign_id', $campaign->id)->firstOrFail();

    app(DeliverSmsRecipient::class)->handle($recipient);
    app(DeliverSmsRecipient::class)->handle($recipient->refresh());
    app(DeliverSmsRecipient::class)->handle($recipient->refresh());

    // One submission, one attempt row — the recipient's own status is the claim.
    expect(SmsDeliveryAttempt::query()->where('recipient_id', $recipient->id)->count())->toBe(1)
        ->and(app(FakeSmsProviderClient::class)->sentReferences)->toHaveCount(1);
});

it('rolls a mixed outcome up to partially_failed and bills every dispatched recipient', function (): void {
    $scn = smsScenario();
    $second = smsServedClient($scn['merchant'], $scn['branch'], $scn['staff'], $scn['service'], phone: '+254733444555');

    Queue::fake();
    $ulid = smsDraft($scn['user'], [$scn['client']->ulid, $second->ulid])->json('data.id');
    smsConfirm($scn['user'], $ulid);
    $campaign = PersonnelSmsCampaign::query()->where('ulid', $ulid)->firstOrFail();

    $recipients = PersonnelSmsRecipient::query()->where('campaign_id', $campaign->id)->orderBy('id')->get();

    app(FakeSmsProviderClient::class)->queueResult(
        SmsSendResult::accepted('FAKE-OK-1'),
        SmsSendResult::failure(SmsProviderResultClass::InvalidRecipient, 'invalid_recipient'),
    );

    foreach ($recipients as $recipient) {
        app(DeliverSmsRecipient::class)->handle($recipient);
    }

    expect($campaign->refresh()->status)->toBe(PersonnelSmsCampaignStatus::PartiallyFailed);

    // Both were handed to the provider, so both are billable: 2 recipients × 1 segment × 100.
    $entry = SmsBillingEntry::query()->where('campaign_id', $campaign->id)->where('status', SmsBillingEntryStatus::Billable)->firstOrFail();
    expect($entry->quantity)->toBe(2)->and($entry->amount_minor)->toBe(200);
});

it('does not bill a recipient the provider reported as opted out', function (): void {
    $scn = smsScenario();
    $second = smsServedClient($scn['merchant'], $scn['branch'], $scn['staff'], $scn['service'], phone: '+254733666777');

    Queue::fake();
    $ulid = smsDraft($scn['user'], [$scn['client']->ulid, $second->ulid])->json('data.id');
    smsConfirm($scn['user'], $ulid);
    $campaign = PersonnelSmsCampaign::query()->where('ulid', $ulid)->firstOrFail();
    $recipients = PersonnelSmsRecipient::query()->where('campaign_id', $campaign->id)->orderBy('id')->get();

    app(FakeSmsProviderClient::class)->queueResult(
        SmsSendResult::accepted('FAKE-OK-1'),
        SmsSendResult::failure(SmsProviderResultClass::OptedOut, 'opted_out'),
    );

    foreach ($recipients as $recipient) {
        app(DeliverSmsRecipient::class)->handle($recipient);
    }

    // The provisional entry (2 units) is cancelled and replaced by a billable one for 1.
    $live = SmsBillingEntry::query()->where('campaign_id', $campaign->id)->whereIn('status', SmsBillingEntryStatus::liveValues())->get();
    expect($live)->toHaveCount(1)
        ->and($live->first()->status)->toBe(SmsBillingEntryStatus::Billable)
        ->and($live->first()->quantity)->toBe(1)
        ->and($live->first()->amount_minor)->toBe(100);

    // The correction trail survives.
    expect(SmsBillingEntry::query()->where('campaign_id', $campaign->id)->where('status', SmsBillingEntryStatus::Cancelled)->count())->toBe(1);
});

/*
 |--------------------------------------------------------------------------
 | Redaction + append-only evidence
 |--------------------------------------------------------------------------
 */

it('redacts a provider message that echoes the destination number and the message body', function (): void {
    $scn = smsScenario();
    $campaign = smsQueuedCampaign($scn);
    $recipient = PersonnelSmsRecipient::query()->where('campaign_id', $campaign->id)->firstOrFail();

    app(FakeSmsProviderClient::class)->queueResult(SmsSendResult::failure(
        SmsProviderResultClass::ProviderError,
        'provider_error',
        '{"to":"+254712345678","from":"SERVANA","text":"Thank you for visiting","api_key":"PROVIDERKEYFIXTUREabcdef","error":"gateway timeout for 254712345678"}',
    ));

    app(DeliverSmsRecipient::class)->handle($recipient);

    $stored = (string) SmsDeliveryAttempt::query()->where('recipient_id', $recipient->id)->value('provider_message_redacted');

    expect($stored)
        ->not->toContain('254712345678')
        ->not->toContain('712345678')
        ->not->toContain('PROVIDERKEYFIXTUREabcdef')
        ->not->toContain('Thank you for visiting')
        ->and(strlen($stored))->toBeLessThanOrEqual(512);

    // And no run of 7+ digits survived anywhere.
    expect(preg_match('/\d{7,}/', $stored))->toBe(0);
});

it('refuses at the DATABASE to store an attempt message containing a phone number', function (): void {
    $scn = smsScenario();
    $campaign = smsQueuedCampaign($scn);
    $recipient = PersonnelSmsRecipient::query()->where('campaign_id', $campaign->id)->firstOrFail();

    // Bypassing the redactor entirely still cannot land a number in the table.
    expect(fn () => SmsDeliveryAttempt::query()->create([
        'recipient_id' => $recipient->id,
        'attempt_number' => 99,
        'provider' => 'fake',
        'status' => SmsDeliveryAttemptStatus::PermanentFailure,
        'result_class' => SmsProviderResultClass::InvalidRecipient,
        'provider_code' => 'x',
        'provider_message_redacted' => 'failed for 254712345678',
        'attempted_at' => now(),
    ]))->toThrow(QueryException::class);
});

it('keeps the attempt log append-only', function (): void {
    $scn = smsScenario();
    $campaign = smsQueuedCampaign($scn);
    $recipient = PersonnelSmsRecipient::query()->where('campaign_id', $campaign->id)->firstOrFail();
    app(DeliverSmsRecipient::class)->handle($recipient);

    $attempt = SmsDeliveryAttempt::query()->where('recipient_id', $recipient->id)->firstOrFail();

    expect(fn () => $attempt->forceFill(['provider_code' => 'tampered'])->save())->toThrow(QueryException::class);
    expect(fn () => $attempt->delete())->toThrow(QueryException::class);
});

/*
 |--------------------------------------------------------------------------
 | Delivery receipts (internal only in Phase 21S — REM-SMS-002)
 |--------------------------------------------------------------------------
 */

it('ships NO provider delivery-receipt route (REM-SMS-002)', function (): void {
    // Structural, not HTTP-status based: NO registered route is an SMS receipt/callback surface.
    // Plan §24.1 forbids an unverifiable provider webhook, and no authenticated receipt contract
    // exists because no provider is contracted — REM-SMS-002 owns bringing one online.
    $offenders = [];

    foreach (Route::getRoutes()->getRoutes() as $route) {
        $uri = strtolower($route->uri());

        if (! str_contains($uri, 'sms')) {
            continue;
        }

        foreach (['receipt', 'callback', 'webhook', 'delivery-report', 'dlr', 'status-callback'] as $token) {
            if (str_contains($uri, $token)) {
                $offenders[] = $route->methods()[0].' '.$uri;
            }
        }
    }

    expect($offenders)->toBe([], 'No SMS receipt/callback route may exist in Phase 21S');

    // And the ProviderWebhookMutation route class is used by nothing in this phase.
    foreach (Route::getRoutes()->getRoutes() as $route) {
        if (str_contains(strtolower($route->uri()), 'sms')) {
            expect($route->defaults[RouteClassification::KEY] ?? null)
                ->not->toBe(RouteClass::ProviderWebhookMutation->value);
        }
    }
});

it('applies an internal receipt idempotently and never reopens a settled campaign', function (): void {
    $scn = smsScenario();
    config()->set('sms.delivery.receipts_enabled', true);
    $campaign = smsQueuedCampaign($scn);
    $recipient = PersonnelSmsRecipient::query()->where('campaign_id', $campaign->id)->firstOrFail();

    app(DeliverSmsRecipient::class)->handle($recipient);
    expect($recipient->refresh()->delivery_status)->toBe(PersonnelSmsRecipientDeliveryStatus::Sent)
        // With receipts ON, `sent` is still outstanding — the campaign has NOT settled.
        ->and($campaign->refresh()->status)->toBe(PersonnelSmsCampaignStatus::Sending);

    expect(app(RecordSmsDeliveryReceipt::class)->handle($recipient, SmsProviderResultClass::Accepted, 'delivered'))->toBeTrue();
    expect($recipient->refresh()->delivery_status)->toBe(PersonnelSmsRecipientDeliveryStatus::Delivered)
        ->and($campaign->refresh()->status)->toBe(PersonnelSmsCampaignStatus::Completed);

    // A duplicate / out-of-order receipt changes nothing.
    expect(app(RecordSmsDeliveryReceipt::class)->handle($recipient, SmsProviderResultClass::InvalidRecipient, 'failed'))->toBeFalse();
    expect($recipient->refresh()->delivery_status)->toBe(PersonnelSmsRecipientDeliveryStatus::Delivered)
        ->and($campaign->refresh()->status)->toBe(PersonnelSmsCampaignStatus::Completed);
});

<?php

declare(strict_types=1);

use App\Domain\Audit\Contracts\AuditRecorder;
use App\Domain\Audit\Enums\AuditEvent;
use App\Domain\Audit\Models\AuditLog;
use App\Domain\Audit\Services\DatabaseAuditRecorder;
use App\Domain\Integrations\ReferEarn\Clients\FakeReferEarnClient;
use App\Domain\Integrations\ReferEarn\Data\ReferralCaptureData;
use App\Domain\Integrations\ReferEarn\Enums\ReferralCaptureChannel;
use App\Domain\Integrations\ReferEarn\Enums\ReferralSnapshotStatus;
use App\Domain\Integrations\ReferEarn\Enums\ReOutboundEventType;
use App\Domain\Integrations\ReferEarn\Jobs\ValidateReferralCodeJob;
use App\Domain\Integrations\ReferEarn\Models\ReferralSnapshot;
use App\Domain\Integrations\ReferEarn\Models\ReOutboundEvent;
use App\Domain\Merchants\Models\Merchant;
use App\Domain\Onboarding\Actions\RegisterMerchant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class)->group('referearn', 'phase21ra', 'phase21ra-capture');

/*
 | Referral capture at merchant self-registration — Plan §58A.1, §12.1 item 5, §58B.5 R-01/R-02/R-03.
 |
 | The load-bearing claims proven here:
 |   - the snapshot is written INSIDE the registration transaction (rollback ⇒ no snapshot, no event);
 |   - registration NEVER fails because of R&E (A-19), including when the referral subsystem itself
 |     throws;
 |   - a malformed code becomes `invalid_format` evidence and is NEVER sent to R&E;
 |   - the raw code is encrypted and the landing metadata is allowlisted;
 |   - at most one snapshot exists per merchant;
 |   - the two registration events are enqueued atomically, and only when eligible.
 */

function registerPayload(array $overrides = []): array
{
    return array_merge([
        'owner_name' => 'Amina Wanjiru',
        'email' => 'amina+'.uniqid().'@example.com',
        'business_name' => 'Glow Studio',
    ], $overrides);
}

it('captures a query-param referral inside the registration transaction', function (): void {
    Queue::fake();

    $response = $this->postJson('/api/v1/merchant-registration/self-register', registerPayload([
        'referral_code' => 'SERVANA-X8T2K',
        'referral_channel' => 'query_param',
    ]));

    $response->assertAccepted();

    $snapshot = ReferralSnapshot::query()->sole();

    expect($snapshot->snapshot_status)->toBe(ReferralSnapshotStatus::Captured)
        ->and($snapshot->capture_channel)->toBe(ReferralCaptureChannel::QueryParam)
        ->and($snapshot->code_normalized)->toBe('SERVANA-X8T2K')
        ->and($snapshot->raw_code_encrypted)->toBe('SERVANA-X8T2K')
        ->and($snapshot->captured_at)->not->toBeNull()
        ->and($snapshot->merchant_id)->toBe(Merchant::query()->sole()->id);

    Queue::assertPushed(ValidateReferralCodeJob::class, 1);
});

it('captures a manually entered referral and normalizes it', function (): void {
    Queue::fake();

    $this->postJson('/api/v1/merchant-registration/self-register', registerPayload([
        // Lowercase, padded, and with an internal space a paste could introduce.
        'referral_code' => '  servana-x8 t2k  ',
        'referral_channel' => 'manual_entry',
    ]))->assertAccepted();

    $snapshot = ReferralSnapshot::query()->sole();

    expect($snapshot->code_normalized)->toBe('SERVANA-X8T2K')
        ->and($snapshot->capture_channel)->toBe(ReferralCaptureChannel::ManualEntry)
        // The RAW submission is preserved as evidence — referral normalization never rewrites it.
        // (Laravel's global TrimStrings middleware strips the outer padding before the request is
        // ever validated, so "as submitted" means "as received by the application".)
        ->and($snapshot->raw_code_encrypted)->toBe('servana-x8 t2k');
});

it('defaults an unstated channel to manual_entry rather than claiming provenance', function (): void {
    Queue::fake();

    $this->postJson('/api/v1/merchant-registration/self-register', registerPayload([
        'referral_code' => 'SERVANA-ABCDE',
    ]))->assertAccepted();

    expect(ReferralSnapshot::query()->sole()->capture_channel)->toBe(ReferralCaptureChannel::ManualEntry);
});

it('accepts the central-redirect channel', function (): void {
    Queue::fake();

    $this->postJson('/api/v1/merchant-registration/self-register', registerPayload([
        'referral_code' => 'SERVANA-ABCDE',
        'referral_channel' => 'central_redirect',
    ]))->assertAccepted();

    expect(ReferralSnapshot::query()->sole()->capture_channel)->toBe(ReferralCaptureChannel::CentralRedirect);
});

it('stores a malformed code as invalid_format and never sends it to R&E (R-02)', function (): void {
    Queue::fake();

    $this->postJson('/api/v1/merchant-registration/self-register', registerPayload([
        'referral_code' => 'not-a-servana-code',
        'referral_channel' => 'query_param',
    ]))->assertAccepted();

    $snapshot = ReferralSnapshot::query()->sole();

    expect($snapshot->snapshot_status)->toBe(ReferralSnapshotStatus::InvalidFormat)
        ->and($snapshot->code_normalized)->toBeNull()
        // Evidence of what was actually submitted survives, encrypted.
        ->and($snapshot->raw_code_encrypted)->toBe('not-a-servana-code');

    // Nothing is queued, so nothing can reach the partner.
    Queue::assertNotPushed(ValidateReferralCodeJob::class);

    // And no merchant fact is emitted for a claim that was never valid.
    expect(ReOutboundEvent::query()->count())->toBe(0);
});

it('registers successfully with no referral at all and emits nothing', function (): void {
    Queue::fake();

    $this->postJson('/api/v1/merchant-registration/self-register', registerPayload())->assertAccepted();

    expect(Merchant::query()->count())->toBe(1)
        ->and(ReferralSnapshot::query()->count())->toBe(0)
        ->and(ReOutboundEvent::query()->count())->toBe(0);

    Queue::assertNotPushed(ValidateReferralCodeJob::class);
});

it('never fails registration when the referral subsystem throws (A-19, R-03)', function (): void {
    Queue::fake();

    // Inject a REAL fault into the capture step: the audit write that CaptureReferralSnapshot
    // performs inside its savepoint throws. Everything else audits normally, so this isolates the
    // referral subsystem. Plan A-19 says the registering business must never notice.
    $this->app->bind(AuditRecorder::class, fn ($app): AuditRecorder => new class($app->make(DatabaseAuditRecorder::class)) implements AuditRecorder
    {
        public function __construct(private readonly AuditRecorder $inner) {}

        public function record(AuditEvent $event, ?User $actor = null, ?int $merchantId = null, ?int $branchId = null, ?object $subject = null, array $context = []): AuditLog
        {
            if ($event === AuditEvent::ReReferralCaptured) {
                throw new RuntimeException('referral subsystem fault');
            }

            return $this->inner->record($event, $actor, $merchantId, $branchId, $subject, $context);
        }
    });

    $response = $this->postJson('/api/v1/merchant-registration/self-register', registerPayload([
        'referral_code' => 'SERVANA-X8T2K',
    ]));

    // Registration still succeeds; the merchant, its owner and its membership all exist.
    $response->assertAccepted();

    $merchant = Merchant::query()->sole();

    expect($merchant->status->value)->toBe('pending_setup')
        ->and($merchant->merchantUsers()->count())->toBe(1)
        // The savepoint rolled back only the referral capture: no snapshot, and therefore no event.
        ->and(ReferralSnapshot::query()->count())->toBe(0)
        ->and(ReOutboundEvent::query()->count())->toBe(0);
});

it('leaves no snapshot and no event when the registration transaction rolls back', function (): void {
    Queue::fake();

    $merchantCountBefore = Merchant::query()->count();

    // Force a failure AFTER the snapshot + events would have been written, inside the same
    // transaction, by making the audit chain's last write impossible.
    $register = app(RegisterMerchant::class);

    try {
        DB::transaction(function () use ($register): void {
            $register->handle(
                'Amina Wanjiru',
                'rollback@example.com',
                'Glow Studio',
                new ReferralCaptureData(
                    'SERVANA-X8T2K',
                    ReferralCaptureChannel::QueryParam,
                ),
            );

            throw new RuntimeException('force rollback');
        });
    } catch (RuntimeException) {
        // expected
    }

    expect(Merchant::query()->count())->toBe($merchantCountBefore)
        ->and(ReferralSnapshot::query()->count())->toBe(0)
        ->and(ReOutboundEvent::query()->count())->toBe(0)
        ->and(User::query()->where('email', 'rollback@example.com')->exists())->toBeFalse();
});

it('keeps only allowlisted, non-PII landing metadata', function (): void {
    Queue::fake();

    $this->postJson('/api/v1/merchant-registration/self-register', registerPayload([
        'referral_code' => 'SERVANA-X8T2K',
        'referral_channel' => 'query_param',
        'referral_landing_metadata' => [
            'utm_source' => 'instagram',
            'utm_campaign' => 'jan-launch',
            'landing_path' => '/register',
            // Every one of these must be dropped: they are PII or unsanctioned tracking.
            'email' => 'someone@example.com',
            'phone' => '+254712345678',
            'ip' => '196.201.214.200',
            'user_agent' => 'Mozilla/5.0',
            'referrer_name' => 'Jane the Referrer',
            'notes' => 'free text',
        ],
    ]))->assertAccepted();

    $metadata = ReferralSnapshot::query()->sole()->landing_metadata ?? [];

    // Key ORDER is decided by PostgreSQL jsonb (length, then bytes), not by the filter, so compare
    // the SET of surviving keys. What matters is that exactly the allowlisted three survived.
    $keys = array_keys($metadata);
    sort($keys);

    expect($keys)->toBe(['landing_path', 'utm_campaign', 'utm_source'])
        ->and($metadata['utm_source'])->toBe('instagram');
});

it('stores no landing metadata at all when nothing survives the allowlist', function (): void {
    Queue::fake();

    $this->postJson('/api/v1/merchant-registration/self-register', registerPayload([
        'referral_code' => 'SERVANA-X8T2K',
        'referral_landing_metadata' => ['email' => 'someone@example.com', 'notes' => 'hello'],
    ]))->assertAccepted();

    expect(ReferralSnapshot::query()->sole()->landing_metadata)->toBeNull();
});

it('allows at most one snapshot per merchant even under a repeated registration attempt', function (): void {
    Queue::fake();

    $payload = registerPayload(['referral_code' => 'SERVANA-X8T2K', 'email' => 'dup@example.com']);

    $this->postJson('/api/v1/merchant-registration/self-register', $payload)->assertAccepted();
    // The same email registers again: no second merchant is created, so no second snapshot either.
    $this->postJson('/api/v1/merchant-registration/self-register', $payload)->assertAccepted();

    expect(Merchant::query()->count())->toBe(1)
        ->and(ReferralSnapshot::query()->count())->toBe(1);
});

it('enqueues registration_started and admin_created atomically for a referred merchant', function (): void {
    Queue::fake();

    $this->postJson('/api/v1/merchant-registration/self-register', registerPayload([
        'referral_code' => 'SERVANA-X8T2K',
        'referral_channel' => 'query_param',
    ]))->assertAccepted();

    $events = ReOutboundEvent::query()->orderBy('sequence_no')->get();

    expect($events)->toHaveCount(2)
        ->and($events[0]->event_type)->toBe(ReOutboundEventType::MerchantRegistrationStarted)
        ->and($events[1]->event_type)->toBe(ReOutboundEventType::MerchantAdminCreated)
        ->and($events[0]->sequence_no)->toBe(1)
        ->and($events[1]->sequence_no)->toBe(2)
        ->and($events[0]->merchant_public_id)->toBe(Merchant::query()->sole()->ulid);
});

it('audits the capture without ever recording the referral code', function (): void {
    Queue::fake();

    $this->postJson('/api/v1/merchant-registration/self-register', registerPayload([
        'referral_code' => 'SERVANA-X8T2K',
        'referral_channel' => 'query_param',
        'referral_landing_metadata' => ['utm_source' => 'instagram'],
    ]))->assertAccepted();

    $audit = AuditLog::query()->where('action', AuditEvent::ReReferralCaptured->value)->sole();

    $context = json_encode($audit->context);

    expect($context)->not->toContain('SERVANA-X8T2K')
        ->and($audit->context['capture_channel'])->toBe('query_param')
        ->and($audit->context['snapshot_status'])->toBe('captured')
        ->and($audit->context['landing_metadata_keys'])->toBe(['utm_source']);
});

it('never contacts R&E during the registration request itself', function (): void {
    Queue::fake();

    $this->postJson('/api/v1/merchant-registration/self-register', registerPayload([
        'referral_code' => 'SERVANA-X8T2K',
    ]))->assertAccepted();

    $fake = app(FakeReferEarnClient::class);

    expect($fake->validatedCodes)->toBe([])
        ->and($fake->confirmedAttributions)->toBe([])
        ->and($fake->deliveredEvents)->toBe([]);
});

<?php

declare(strict_types=1);

use App\Domain\Audit\Enums\AuditEvent;
use App\Domain\Audit\Models\AuditLog;
use App\Domain\Integrations\ReferEarn\Actions\ConfirmAttribution;
use App\Domain\Integrations\ReferEarn\Actions\ValidateReferralCode;
use App\Domain\Integrations\ReferEarn\Clients\Dto\AttributionConfirmation;
use App\Domain\Integrations\ReferEarn\Clients\Dto\ReferralCodeValidation;
use App\Domain\Integrations\ReferEarn\Clients\FakeReferEarnClient;
use App\Domain\Integrations\ReferEarn\Enums\ReferralCaptureChannel;
use App\Domain\Integrations\ReferEarn\Enums\ReferralSnapshotStatus;
use App\Domain\Integrations\ReferEarn\Jobs\ConfirmAttributionJob;
use App\Domain\Integrations\ReferEarn\Models\ReferralSnapshot;
use App\Domain\Merchants\Models\Merchant;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class)->group('referearn', 'phase21ra', 'phase21ra-attribution');

/*
 | Attribution lifecycle — Plan §58A.1, §58A.2, §25.6, §58B.5 R-03/R-04.
 |
 | Proves validate → confirm, R-04 attribution-conflict rejection, the "retries stay in the same
 | state" contract when R&E gives no verdict, the trigger-enforced non-regression, and the schema
 | assertion that no referrer PII column exists.
 */

beforeEach(function (): void {
    $this->fake = app(FakeReferEarnClient::class);
});

it('walks a captured snapshot through validate to confirmed', function (): void {
    Queue::fake();

    $snapshot = ReferralSnapshot::query()->create(referralSnapshotAttributes());

    $this->fake->queueValidation(ReferralCodeValidation::valid('VALID'));

    expect(app(ValidateReferralCode::class)->handle($snapshot))->toBeTrue();

    $snapshot->refresh();

    expect($snapshot->snapshot_status)->toBe(ReferralSnapshotStatus::Validated)
        ->and($snapshot->re_validation_result_code)->toBe('VALID')
        ->and($this->fake->validatedCodes)->toHaveCount(1)
        ->and($this->fake->validatedCodes[0]['code'])->toBe('SERVANA-X8T2K');

    // Confirmation is its own queued step, not an inline call.
    Queue::assertPushed(ConfirmAttributionJob::class, 1);

    $this->fake->queueConfirmation(AttributionConfirmation::confirmed('ATTR-PUBLIC-1'));

    expect(app(ConfirmAttribution::class)->handle($snapshot->refresh()))->toBeTrue();

    $snapshot->refresh();

    expect($snapshot->snapshot_status)->toBe(ReferralSnapshotStatus::Confirmed)
        ->and($snapshot->re_attribution_public_id)->toBe('ATTR-PUBLIC-1')
        ->and($snapshot->confirmed_at)->not->toBeNull();
});

it('rejects a snapshot when R&E says the code is invalid', function (): void {
    Queue::fake();

    $snapshot = ReferralSnapshot::query()->create(referralSnapshotAttributes());

    $this->fake->queueValidation(ReferralCodeValidation::invalid('CODE_EXPIRED'));

    expect(app(ValidateReferralCode::class)->handle($snapshot))->toBeTrue();

    $snapshot->refresh();

    expect($snapshot->snapshot_status)->toBe(ReferralSnapshotStatus::Rejected)
        ->and($snapshot->re_validation_result_code)->toBe('CODE_EXPIRED');

    Queue::assertNotPushed(ConfirmAttributionJob::class);
});

it('rejects at confirm time when another referrer is already effective (R-04)', function (): void {
    $snapshot = ReferralSnapshot::factory()->validated()->create();

    $this->fake->queueConfirmation(AttributionConfirmation::rejected('ATTRIBUTION_CONFLICT'));

    expect(app(ConfirmAttribution::class)->handle($snapshot))->toBeTrue();

    $snapshot->refresh();

    expect($snapshot->snapshot_status)->toBe(ReferralSnapshotStatus::Rejected)
        ->and($snapshot->re_validation_result_code)->toBe('ATTRIBUTION_CONFLICT')
        ->and($snapshot->re_attribution_public_id)->toBeNull()
        // The claim is settled against the referrer: nothing more is streamed for this merchant.
        ->and($snapshot->permitsEventEmission())->toBeFalse();

    expect(AuditLog::query()->where('action', AuditEvent::ReAttributionRejected->value)->exists())->toBeTrue();
});

it('keeps the snapshot in validating when R&E gives no verdict (R-03)', function (): void {
    Queue::fake();

    $snapshot = ReferralSnapshot::query()->create(referralSnapshotAttributes());

    $this->fake->queueValidation(ReferralCodeValidation::retryable());

    expect(app(ValidateReferralCode::class)->handle($snapshot))->toBeFalse();

    // A retry is NOT a state change (Plan §25.6): the snapshot stays exactly where it was.
    expect($snapshot->refresh()->snapshot_status)->toBe(ReferralSnapshotStatus::Validating);

    // A later successful attempt still settles it.
    $this->fake->queueValidation(ReferralCodeValidation::valid('VALID'));

    expect(app(ValidateReferralCode::class)->handle($snapshot->refresh()))->toBeTrue()
        ->and($snapshot->refresh()->snapshot_status)->toBe(ReferralSnapshotStatus::Validated);
});

it('keeps the snapshot validated when confirmation gives no verdict', function (): void {
    $snapshot = ReferralSnapshot::factory()->validated()->create();

    $this->fake->queueConfirmation(AttributionConfirmation::retryable());

    expect(app(ConfirmAttribution::class)->handle($snapshot))->toBeFalse()
        ->and($snapshot->refresh()->snapshot_status)->toBe(ReferralSnapshotStatus::Validated);
});

it('treats a confirmation with no attribution id as no verdict, never as confirmed', function (): void {
    $snapshot = ReferralSnapshot::factory()->validated()->create();

    // Fail closed: a "success" without evidence must not be recorded as confirmed.
    $this->fake->queueConfirmation(AttributionConfirmation::rejected(null));

    app(ConfirmAttribution::class)->handle($snapshot);

    expect($snapshot->refresh()->snapshot_status)->toBe(ReferralSnapshotStatus::Rejected)
        ->and($snapshot->re_attribution_public_id)->toBeNull();
});

it('never sends a malformed code to R&E even if a job reaches it', function (): void {
    $snapshot = ReferralSnapshot::factory()->invalidFormat()->create();

    expect(app(ValidateReferralCode::class)->handle($snapshot))->toBeFalse()
        ->and($this->fake->validatedCodes)->toBe([])
        ->and($snapshot->refresh()->snapshot_status)->toBe(ReferralSnapshotStatus::InvalidFormat);
});

it('refuses to re-validate a settled snapshot', function (): void {
    foreach ([ReferralSnapshot::factory()->confirmed(), ReferralSnapshot::factory()->rejected()] as $factory) {
        $snapshot = $factory->create();

        expect(app(ValidateReferralCode::class)->handle($snapshot))->toBeFalse();
    }

    expect($this->fake->validatedCodes)->toBe([]);
});

it('blocks a status regression at the database level', function (): void {
    $snapshot = ReferralSnapshot::factory()->confirmed()->create();

    // Bypassing the action entirely still cannot regress the row: the trigger is the backstop.
    expect(fn () => DB::table('referral_snapshots')->where('id', $snapshot->id)->update(['snapshot_status' => 'captured']))
        ->toThrow(QueryException::class);
});

it('has no referrer-identity column anywhere on referral_snapshots', function (): void {
    $columns = Schema::getColumnListing('referral_snapshots');

    expect($columns)->toBe([
        'id', 'ulid', 'merchant_id', 'raw_code_encrypted', 'code_normalized', 'capture_channel',
        'captured_at', 'landing_metadata', 'snapshot_status', 're_validation_result_code',
        're_attribution_public_id', 'confirmed_at', 'last_transition_at', 'created_at', 'updated_at',
    ]);
});

it('audits a confirmation with the opaque attribution id and never the code', function (): void {
    $snapshot = ReferralSnapshot::factory()->validated()->create();

    $this->fake->queueConfirmation(AttributionConfirmation::confirmed('ATTR-PUBLIC-9'));

    app(ConfirmAttribution::class)->handle($snapshot);

    $audit = AuditLog::query()->where('action', AuditEvent::ReAttributionConfirmed->value)->sole();
    $context = json_encode($audit->context);

    expect($audit->context['attribution_public_id'])->toBe('ATTR-PUBLIC-9')
        ->and($context)->not->toContain($snapshot->code_normalized ?? 'SERVANA-');
});

/** @return array<string, mixed> */
function referralSnapshotAttributes(array $overrides = []): array
{
    $merchant = Merchant::factory()->create();

    return array_merge([
        'merchant_id' => $merchant->id,
        'raw_code_encrypted' => 'SERVANA-X8T2K',
        'code_normalized' => 'SERVANA-X8T2K',
        'capture_channel' => ReferralCaptureChannel::QueryParam,
        'captured_at' => now(),
        'snapshot_status' => ReferralSnapshotStatus::Captured,
        'last_transition_at' => now(),
    ], $overrides);
}

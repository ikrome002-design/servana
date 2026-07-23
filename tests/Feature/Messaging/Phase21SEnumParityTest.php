<?php

declare(strict_types=1);

use App\Domain\Clients\Enums\ConsentState;
use App\Domain\Messaging\Sms\Enums\PersonnelSmsCampaignStatus;
use App\Domain\Messaging\Sms\Enums\PersonnelSmsRecipientDeliveryStatus;
use App\Domain\Messaging\Sms\Enums\SmsBillingEntryStatus;
use App\Domain\Messaging\Sms\Enums\SmsConsentSnapshotStatus;
use App\Domain\Messaging\Sms\Enums\SmsDeliveryAttemptStatus;
use App\Domain\Messaging\Sms\Enums\SmsProviderResultClass;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class)->group('messaging', 'sms', 'phase21s', 'schema');

/*
 | Phase 21S enum ↔ DB CHECK parity. Every PHP enum's backing values must appear in its column's
 | CHECK clause, and the CHECK must admit nothing the enum does not — so a value can never be
 | written that the domain cannot represent, and vice versa.
 */

function p21sParityClause(string $constraint): string
{
    return (string) DB::table('pg_constraint')
        ->where('conname', $constraint)
        ->selectRaw('pg_get_constraintdef(oid) as def')
        ->value('def');
}

/**
 * Extract the quoted literals from a CHECK ... IN (...) clause.
 *
 * @return list<string>
 */
function p21sClauseValues(string $constraint): array
{
    preg_match_all("/'([a-z_]+)'/", p21sParityClause($constraint), $matches);

    /** @var list<string> $values */
    $values = array_values(array_unique($matches[1]));
    sort($values);

    return $values;
}

/** @param list<string> $expected */
function expectEnumParity(string $constraint, array $expected): void
{
    $sorted = $expected;
    sort($sorted);

    expect(p21sClauseValues($constraint))->toBe($sorted, "{$constraint} must admit exactly the enum values");
}

it('keeps PersonnelSmsCampaignStatus in exact parity with its CHECK', function (): void {
    expect(PersonnelSmsCampaignStatus::values())->toBe([
        'draft', 'confirmed', 'queued', 'sending', 'completed', 'partially_failed', 'failed', 'cancelled',
    ]);

    expectEnumParity('personnel_sms_campaigns_status_check', PersonnelSmsCampaignStatus::values());
});

it('keeps PersonnelSmsRecipientDeliveryStatus in exact parity with its CHECK', function (): void {
    expect(PersonnelSmsRecipientDeliveryStatus::values())->toBe([
        'pending', 'sent', 'delivered', 'failed', 'opted_out', 'suppressed',
    ]);

    expectEnumParity('personnel_sms_recipients_delivery_status_check', PersonnelSmsRecipientDeliveryStatus::values());
});

it('keeps SmsConsentSnapshotStatus in exact parity with its CHECK', function (): void {
    expect(SmsConsentSnapshotStatus::values())->toBe(['opted_in', 'opted_out', 'missing']);

    expectEnumParity('personnel_sms_recipients_consent_snapshot_check', SmsConsentSnapshotStatus::values());
});

it('keeps SmsDeliveryAttemptStatus and SmsProviderResultClass in exact parity with their CHECKs', function (): void {
    expect(SmsDeliveryAttemptStatus::values())->toBe(['accepted', 'transient_failure', 'permanent_failure']);
    expectEnumParity('sms_delivery_attempts_status_check', SmsDeliveryAttemptStatus::values());

    expect(SmsProviderResultClass::values())->toBe([
        'accepted', 'invalid_recipient', 'opted_out', 'rate_limited', 'insufficient_balance',
        'provider_error', 'transport_error', 'unauthorized', 'unexpected',
    ]);
    expectEnumParity('sms_delivery_attempts_result_class_check', SmsProviderResultClass::values());
});

it('keeps SmsBillingEntryStatus in exact parity with its CHECK', function (): void {
    expect(SmsBillingEntryStatus::values())->toBe([
        'provisional', 'billable', 'invoiced', 'credited', 'cancelled',
    ]);

    expectEnumParity('sms_billing_entries_status_check', SmsBillingEntryStatus::values());
});

it('extends the 15A consent vocabulary with `missing`, because absence is never consent', function (): void {
    // ConsentState (15A) records a DECISION and therefore has no value for "no row exists".
    expect(array_map(static fn (ConsentState $s): string => $s->value, ConsentState::cases()))
        ->toBe(['opted_in', 'opted_out']);

    // The SMS snapshot must be able to say so explicitly, and it must fail closed.
    expect(SmsConsentSnapshotStatus::fromConsentState(null))->toBe(SmsConsentSnapshotStatus::Missing)
        ->and(SmsConsentSnapshotStatus::Missing->permitsDelivery())->toBeFalse()
        ->and(SmsConsentSnapshotStatus::OptedOut->permitsDelivery())->toBeFalse()
        ->and(SmsConsentSnapshotStatus::OptedIn->permitsDelivery())->toBeTrue();
});

it('classifies exactly two provider result classes as permanent', function (): void {
    $permanent = array_values(array_filter(
        SmsProviderResultClass::cases(),
        static fn (SmsProviderResultClass $c): bool => $c->isPermanentFailure(),
    ));

    // Plan §64: retry transient, NEVER permanent invalid/opt-out. Retrying an opt-out would also be
    // a consent violation, so widening this set is a deliberate, visible change.
    expect($permanent)->toBe([
        SmsProviderResultClass::InvalidRecipient,
        SmsProviderResultClass::OptedOut,
    ]);

    // Everything else that is not `accepted` is retriable — including `unexpected`, so an unknown
    // provider behaviour dead-letters visibly instead of silently dropping a message.
    foreach (SmsProviderResultClass::cases() as $class) {
        if ($class === SmsProviderResultClass::Accepted) {
            expect($class->isTransientFailure())->toBeFalse();

            continue;
        }

        expect($class->isTransientFailure())->toBe(! $class->isPermanentFailure());
    }

    // A provider-reported opt-out is recorded as a CONSENT fact, not a generic failure.
    expect(SmsProviderResultClass::OptedOut->permanentRecipientStatus())
        ->toBe(PersonnelSmsRecipientDeliveryStatus::OptedOut)
        ->and(SmsProviderResultClass::InvalidRecipient->permanentRecipientStatus())
        ->toBe(PersonnelSmsRecipientDeliveryStatus::Failed);
});

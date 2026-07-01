<?php

declare(strict_types=1);

use App\Domain\Payments\Models\PaymentRecord;
use App\Domain\Payments\Models\PaymentRecordingGroup;
use App\Domain\Payments\Models\PaymentReferenceCheck;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class)->group('payments', 'payment-schema');

it('rejects an unsupported group status via the DB CHECK', function (): void {
    $group = PaymentRecordingGroup::factory()->create();
    expect(fn () => DB::table('payment_recording_groups')->where('id', $group->id)->update(['status' => 'bogus']))
        ->toThrow(QueryException::class);
});

it('rejects a non-positive group total via the DB CHECK', function (): void {
    $group = PaymentRecordingGroup::factory()->create();
    expect(fn () => DB::table('payment_recording_groups')->where('id', $group->id)->update(['total_amount_minor' => 0]))
        ->toThrow(QueryException::class);
});

it('rejects a non-uppercase currency via the DB CHECK', function (): void {
    $group = PaymentRecordingGroup::factory()->create();
    expect(fn () => DB::table('payment_recording_groups')->where('id', $group->id)->update(['currency' => 'kes']))
        ->toThrow(QueryException::class);
});

it('rejects a non-positive component amount via the DB CHECK', function (): void {
    $record = PaymentRecord::factory()->create();
    expect(fn () => DB::table('payment_records')->where('id', $record->id)->update(['amount_minor' => 0]))
        ->toThrow(QueryException::class);
});

it('rejects an unsupported payment method via the DB CHECK', function (): void {
    $record = PaymentRecord::factory()->create();
    expect(fn () => DB::table('payment_records')->where('id', $record->id)->update(['method' => 'crypto']))
        ->toThrow(QueryException::class);
});

it('requires a reference for a reference-bearing method via the coherence CHECK', function (): void {
    $record = PaymentRecord::factory()->referenced()->create();
    expect(fn () => DB::table('payment_records')->where('id', $record->id)->update(['reference_normalized' => null]))
        ->toThrow(QueryException::class);
});

it('allows cash to carry no reference', function (): void {
    $record = PaymentRecord::factory()->create(['method' => 'cash', 'reference_normalized' => null]);
    expect($record->reference_normalized)->toBeNull();
});

it('reserves at most one unique reference per merchant+method (partial unique index)', function (): void {
    $check = PaymentReferenceCheck::factory()->create();

    // A second `unique` reservation for the same (merchant, method, reference) is rejected.
    expect(fn () => PaymentReferenceCheck::factory()->create([
        'merchant_id' => $check->merchant_id,
        'branch_id' => $check->branch_id,
        'payment_record_id' => $check->payment_record_id,
        'method' => $check->method->value,
        'reference_normalized' => $check->reference_normalized,
        'result' => 'unique',
    ]))->toThrow(QueryException::class);
});

it('lets many duplicate_suspected rows coexist for the same reference (outside the predicate)', function (): void {
    $check = PaymentReferenceCheck::factory()->create();

    $dup = PaymentReferenceCheck::factory()->create([
        'merchant_id' => $check->merchant_id,
        'branch_id' => $check->branch_id,
        'payment_record_id' => $check->payment_record_id,
        'method' => $check->method->value,
        'reference_normalized' => $check->reference_normalized,
        'result' => 'duplicate_suspected',
        'matched_payment_record_id' => $check->payment_record_id,
    ]);

    expect($dup->result->value)->toBe('duplicate_suspected');
});

it('requires a matched record for a duplicate result via the coherence CHECK', function (): void {
    $check = PaymentReferenceCheck::factory()->create();
    expect(fn () => DB::table('payment_reference_checks')->where('id', $check->id)->update([
        'result' => 'duplicate_suspected', 'matched_payment_record_id' => null,
    ]))->toThrow(QueryException::class);
});

it('requires an actor + reason for an override result via the coherence CHECK', function (): void {
    $check = PaymentReferenceCheck::factory()->create();
    expect(fn () => DB::table('payment_reference_checks')->where('id', $check->id)->update([
        'result' => 'override_approved', 'matched_payment_record_id' => $check->payment_record_id,
        'override_by' => null, 'override_reason' => null,
    ]))->toThrow(QueryException::class);
});

it('never serializes the normalized or encrypted reference and masks the display', function (): void {
    $record = PaymentRecord::factory()->referenced(reference: 'QGX7YT1ABC')->create();

    $array = $record->toArray();
    expect($array)->not->toHaveKey('reference_normalized')
        ->and($array)->not->toHaveKey('reference_display_encrypted')
        ->and($record->maskedReference())->toEndWith('1ABC')
        ->and($record->maskedReference())->toContain('•');
});

it('exposes the ulid as the route key on the group and never the bigint id', function (): void {
    $group = PaymentRecordingGroup::factory()->create();
    expect($group->getRouteKeyName())->toBe('ulid')
        ->and($group->ulid)->toHaveLength(26);
});

it('stores the encrypted display reference at rest (not plaintext)', function (): void {
    $record = PaymentRecord::factory()->referenced(reference: 'QGX7YT1ABC')->create();

    $raw = DB::table('payment_records')->where('id', $record->id)->value('reference_display_encrypted');
    expect($raw)->not->toBe('QGX7YT1ABC')
        ->and($record->reference_display_encrypted)->toBe('QGX7YT1ABC'); // decrypted via cast
});

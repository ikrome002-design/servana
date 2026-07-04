<?php

declare(strict_types=1);

use App\Domain\Payments\Models\PaymentValidationEvent;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

uses(RefreshDatabase::class)->group('payments', 'schema');

it('is append-only: payment_validation_events has created_at but no updated_at', function (): void {
    expect(Schema::hasColumn('payment_validation_events', 'created_at'))->toBeTrue()
        ->and(Schema::hasColumn('payment_validation_events', 'updated_at'))->toBeFalse();
});

it('enforces at most one validated event per group (partial unique index)', function (): void {
    $event = PaymentValidationEvent::factory()->create();

    // A second VALIDATED event for the same group violates the partial unique index.
    expect(fn () => PaymentValidationEvent::factory()->create([
        'payment_recording_group_id' => $event->payment_recording_group_id,
        'merchant_id' => $event->merchant_id,
        'branch_id' => $event->branch_id,
        'invoice_id' => $event->invoice_id,
    ]))->toThrow(QueryException::class);
});

it('allows multiple NON-validated events for the same group (outside the partial index)', function (): void {
    $rejected = PaymentValidationEvent::factory()->rejected()->create();

    $second = PaymentValidationEvent::factory()->correctionRequired()->create([
        'payment_recording_group_id' => $rejected->payment_recording_group_id,
        'merchant_id' => $rejected->merchant_id,
        'branch_id' => $rejected->branch_id,
        'invoice_id' => $rejected->invoice_id,
    ]);

    expect($second->exists)->toBeTrue();
});

it('requires validated_amount for a validated decision (CHECK, not FK)', function (): void {
    // A rejected event seeds valid FK context (merchant/branch/group/invoice/checker)
    // and leaves the group WITHOUT a validated event.
    $base = PaymentValidationEvent::factory()->rejected()->create();

    // A raw `validated` row with a null amount trips the coherence CHECK (FKs valid).
    expect(fn () => DB::table('payment_validation_events')->insert([
        'ulid' => (string) Str::ulid(),
        'merchant_id' => $base->merchant_id, 'branch_id' => $base->branch_id,
        'payment_recording_group_id' => $base->payment_recording_group_id, 'invoice_id' => $base->invoice_id,
        'checker_user_id' => $base->checker_user_id, 'decision' => 'validated',
        'validated_amount_minor' => null, 'reason' => null, 'created_at' => now(),
    ]))->toThrow(QueryException::class);
});

it('requires a reason for a non-validated decision (CHECK, not FK)', function (): void {
    $base = PaymentValidationEvent::factory()->create();

    expect(fn () => DB::table('payment_validation_events')->insert([
        'ulid' => (string) Str::ulid(),
        'merchant_id' => $base->merchant_id, 'branch_id' => $base->branch_id,
        'payment_recording_group_id' => $base->payment_recording_group_id, 'invoice_id' => $base->invoice_id,
        'checker_user_id' => $base->checker_user_id, 'decision' => 'rejected',
        'validated_amount_minor' => null, 'reason' => null, 'created_at' => now(),
    ]))->toThrow(QueryException::class);
});

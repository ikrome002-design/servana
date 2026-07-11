<?php

declare(strict_types=1);

use App\Domain\Billing\Models\PreferredPersonnelFeeRule;
use App\Domain\Invoicing\Actions\CreateInvoiceDraft;
use App\Domain\Invoicing\Actions\FinalizeInvoice;
use App\Domain\Invoicing\Models\Invoice;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class)->group('invoicing', 'billing', 'preferred-fee-finalization');

/*
 | Phase 20A seam closure (Plan §10, §13.10): future invoice finalization resolves the effective
 | preferred-fee RULE; an already-finalized invoice snapshot is NEVER recalculated when a rule
 | later changes. Uses the shared invoiceScenario()/completedSessionFor() helpers (tests/Pest.php).
 */

it('keeps a finalized invoice snapshot unchanged when the rule later changes, and applies the new rule to future finalizations', function (): void {
    $scn = invoiceScenario(500000);
    $rule = PreferredPersonnelFeeRule::factory()->service($scn['service'])->create([
        'fixed_amount_minor' => 20000, 'currency' => 'KES',
        'effective_from' => '2026-01-01', 'effective_to' => null, 'status' => 'active',
    ]);

    // First finalization snapshots the effective rule (20000).
    $first = app(FinalizeInvoice::class)->handle(
        app(CreateInvoiceDraft::class)->handle($scn['client'], [completedSessionFor($scn, preferredHonored: true)], $scn['actor']),
        $scn['actor'],
    );
    expect($first->preferred_personnel_fee_snapshot_minor)->toBe(20000)
        ->and($first->total_minor)->toBe(520000);

    // Supersede the rule with a higher fee (active terms are immutable — supersede, never edit).
    $rule->update(['status' => 'superseded']);
    PreferredPersonnelFeeRule::factory()->service($scn['service'])->create([
        'fixed_amount_minor' => 30000, 'currency' => 'KES',
        'effective_from' => '2026-01-01', 'effective_to' => null, 'status' => 'active',
    ]);

    // The already-finalized invoice is NEVER recalculated.
    $reloaded = Invoice::query()->whereKey($first->id)->firstOrFail();
    expect($reloaded->preferred_personnel_fee_snapshot_minor)->toBe(20000)
        ->and($reloaded->total_minor)->toBe(520000);

    // A NEW finalization resolves the new effective rule (30000).
    $second = app(FinalizeInvoice::class)->handle(
        app(CreateInvoiceDraft::class)->handle($scn['client'], [completedSessionFor($scn, preferredHonored: true)], $scn['actor']),
        $scn['actor'],
    );
    expect($second->preferred_personnel_fee_snapshot_minor)->toBe(30000)
        ->and($second->total_minor)->toBe(530000);
});

it('charges no preferred fee at finalization when no effective rule exists (rule seam replaces the legacy column)', function (): void {
    // The service still carries a legacy value, but with NO rule the rule-based resolver
    // charges nothing — proving the legacy column is no longer the finalization source.
    $scn = invoiceScenario(500000, preferredFeeMinor: 20000);
    $issued = app(FinalizeInvoice::class)->handle(
        app(CreateInvoiceDraft::class)->handle($scn['client'], [completedSessionFor($scn, preferredHonored: true)], $scn['actor']),
        $scn['actor'],
    );

    expect($issued->preferred_personnel_fee_snapshot_minor)->toBeNull()
        ->and($issued->total_minor)->toBe(500000);
});

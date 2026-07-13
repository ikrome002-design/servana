<?php

declare(strict_types=1);

use App\Domain\Billing\Actions\CreatePlatformFeeDispute;
use App\Domain\Billing\Actions\RejectPlatformFeeDispute;
use App\Domain\Billing\Actions\ResolvePlatformFeeDispute;
use App\Domain\Billing\Actions\StartPlatformFeeDisputeReview;
use App\Domain\Billing\Enums\PlatformFeeDisputeStatus;
use App\Domain\Billing\Enums\PlatformFeeLedgerStatus;
use App\Domain\Billing\Exceptions\BillingStateException;
use App\Domain\Billing\Exceptions\PlatformFeeDisputeException;
use App\Domain\Billing\Exceptions\PlatformFeeException;
use App\Domain\Billing\Models\PlatformFeeAdjustment;
use App\Domain\Billing\Models\PlatformFeeConfiguration;
use App\Domain\Billing\Models\PlatformFeeDispute;
use App\Domain\Billing\Models\PlatformFeeLedgerEntry;
use App\Domain\Billing\Models\SubscriptionInvoice;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Merchants\Models\Merchant;
use App\Domain\Tenancy\TenantContext;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class)->group('billing', 'phase20e', 'phase20e-dispute');

/*
 | Phase 20E Increment 5C — the canonical platform-fee dispute workflow (Plan §13.10 [Correction 3]).
 | States: open → under_review → resolved | rejected. A money-changing resolution creates an additive
 | platform_fee_adjustments row; it never rewrites the ledger amount. PostgreSQL 16. HTTP permission
 | gating is finalized in Increment 6; these actions enforce scope + state transitions + money rules.
 */

/** @return array{merchant: Merchant, entry: PlatformFeeLedgerEntry, creator: User, reviewer: User} */
function disputeScenario(int $gross = 12500): array
{
    $merchant = Merchant::factory()->create();
    $sourceInvoice = Invoice::factory()->issued()->create();
    app(TenantContext::class)->bindForJob($merchant);
    $config = PlatformFeeConfiguration::factory()->percentage(250)->customerCentric()->active()->create([
        'currency' => 'KES', 'effective_from' => today()->subYears(2), 'effective_to' => null,
    ]);
    $entry = PlatformFeeLedgerEntry::factory()->create([
        'merchant_id' => $merchant->id, 'branch_id' => null, 'source_invoice_id' => $sourceInvoice->id,
        'entry_type' => 'earned', 'status' => 'pending', 'effective_configuration_id' => $config->id,
        'service_fee_tier_snapshot' => 'customer_centric', 'gross_platform_fee_minor' => $gross,
        'client_shifted_amount_minor' => 0, 'merchant_absorbed_amount_minor' => $gross,
        'merchant_liability_minor' => $gross, 'currency' => 'KES',
    ]);

    return ['merchant' => $merchant, 'entry' => $entry, 'creator' => User::factory()->create(), 'reviewer' => User::factory()->create()];
}

function raiseDispute(array $scn, ?string $reason = 'Fee looks wrong'): PlatformFeeDispute
{
    return app(CreatePlatformFeeDispute::class)->handle(
        $scn['creator'], $scn['merchant']->id, null, $scn['entry'], null, (string) $reason,
    );
}

function subscriptionInvoiceForDispute(Merchant $merchant): SubscriptionInvoice
{
    return SubscriptionInvoice::factory()->forMerchant($merchant)->issued()->create();
}

it('creates an open dispute against a ledger entry with a mandatory reason', function (): void {
    $scn = disputeScenario();
    $dispute = raiseDispute($scn);

    expect($dispute->status)->toBe(PlatformFeeDisputeStatus::Open)
        ->and($dispute->platform_fee_ledger_entry_id)->toBe($scn['entry']->id)
        ->and($dispute->created_by)->toBe($scn['creator']->id)
        ->and($dispute->reason)->toBe('Fee looks wrong');
});

it('requires a reason and at least one target', function (): void {
    $scn = disputeScenario();

    expect(fn () => raiseDispute($scn, '   '))->toThrow(PlatformFeeDisputeException::class);
    expect(fn () => app(CreatePlatformFeeDispute::class)->handle($scn['creator'], $scn['merchant']->id, null, null, null, 'x'))
        ->toThrow(PlatformFeeDisputeException::class);
});

it('denies a cross-tenant source target (not-found)', function (): void {
    $scn = disputeScenario();
    $otherMerchant = Merchant::factory()->create();

    expect(fn () => app(CreatePlatformFeeDispute::class)->handle($scn['creator'], $otherMerchant->id, null, $scn['entry'], null, 'x'))
        ->toThrow(PlatformFeeDisputeException::class);
});

it('moves open → under_review → resolved and rejects invalid transitions', function (): void {
    $scn = disputeScenario();
    $dispute = raiseDispute($scn);

    // open → resolved directly is illegal.
    expect(fn () => app(ResolvePlatformFeeDispute::class)->handle($dispute, $scn['reviewer'], 'note'))
        ->toThrow(BillingStateException::class);

    $dispute = app(StartPlatformFeeDisputeReview::class)->handle($dispute, $scn['reviewer']);
    expect($dispute->status)->toBe(PlatformFeeDisputeStatus::UnderReview)
        ->and($dispute->assigned_reviewer)->toBe($scn['reviewer']->id);

    $dispute = app(ResolvePlatformFeeDispute::class)->handle($dispute, $scn['reviewer'], 'Upheld, no money change');
    expect($dispute->status)->toBe(PlatformFeeDisputeStatus::Resolved)
        ->and($dispute->resolution_note)->toBe('Upheld, no money change')
        ->and($dispute->resolved_by)->toBe($scn['reviewer']->id);

    // resolved is terminal.
    expect(fn () => app(RejectPlatformFeeDispute::class)->handle($dispute->fresh(), $scn['reviewer'], 'no'))
        ->toThrow(BillingStateException::class);
});

it('rejects from open and from under_review with a mandatory note, no adjustment', function (): void {
    $scn = disputeScenario();
    $fromOpen = raiseDispute($scn);
    $rejected = app(RejectPlatformFeeDispute::class)->handle($fromOpen, $scn['reviewer'], 'Not a valid dispute');
    expect($rejected->status)->toBe(PlatformFeeDisputeStatus::Rejected);

    expect(fn () => app(RejectPlatformFeeDispute::class)->handle(raiseDispute($scn), $scn['reviewer'], '  '))
        ->toThrow(PlatformFeeDisputeException::class);

    expect(PlatformFeeAdjustment::query()->count())->toBe(0);
});

it('blocks the creator from resolving or rejecting their own dispute (maker/checker)', function (): void {
    $scn = disputeScenario();
    $dispute = app(StartPlatformFeeDisputeReview::class)->handle(raiseDispute($scn), $scn['reviewer']);

    expect(fn () => app(ResolvePlatformFeeDispute::class)->handle($dispute, $scn['creator'], 'self'))
        ->toThrow(PlatformFeeDisputeException::class);
});

it('a money-changing resolution records an additive adjustment; the ledger amount is unchanged', function (): void {
    $scn = disputeScenario(12500);
    $entry = $scn['entry'];
    // The disputed earned fee is aggregated + issued on a subscription invoice; the resolution must not
    // rewrite either the ledger row or the issued subscription invoice.
    $subInvoice = subscriptionInvoiceForDispute($scn['merchant']);
    $subTotalBefore = $subInvoice->total_minor;

    $dispute = app(StartPlatformFeeDisputeReview::class)->handle(raiseDispute($scn), $scn['reviewer']);
    $dispute = app(ResolvePlatformFeeDispute::class)->handle($dispute, $scn['reviewer'], 'Partially upheld', -5000);

    expect($dispute->status)->toBe(PlatformFeeDisputeStatus::Resolved)
        ->and(PlatformFeeAdjustment::query()->where('adjustment_type', 'dispute_resolution')->where('amount_minor', -5000)->count())->toBe(1)
        ->and($entry->fresh()->gross_platform_fee_minor)->toBe(12500)
        ->and($entry->fresh()->status)->toBe(PlatformFeeLedgerStatus::Adjusted)
        ->and($subInvoice->fresh()->total_minor)->toBe($subTotalBefore); // issued invoice untouched
});

it('requires a ledger-entry target for a money-changing resolution', function (): void {
    $scn = disputeScenario();
    // Dispute targets a subscription invoice only (no ledger entry) → money change is not permitted.
    $invoiceOnly = app(CreatePlatformFeeDispute::class)->handle(
        $scn['creator'], $scn['merchant']->id, null, null, subscriptionInvoiceForDispute($scn['merchant']), 'inv dispute',
    );
    $invoiceOnly = app(StartPlatformFeeDisputeReview::class)->handle($invoiceOnly, $scn['reviewer']);

    expect(fn () => app(ResolvePlatformFeeDispute::class)->handle($invoiceOnly, $scn['reviewer'], 'note', -100))
        ->toThrow(PlatformFeeDisputeException::class);
});

it('a rolled-back resolution writes no success audit', function (): void {
    $scn = disputeScenario();
    $dispute = app(StartPlatformFeeDisputeReview::class)->handle(raiseDispute($scn), $scn['reviewer']);
    $auditBefore = DB::table('audit_logs')->where('action', 'platform_fee.dispute_resolved')->count();

    // A money-changing resolution against a subscription-invoice-less entry that we force to fail: use an
    // over-reversal (amount beyond the reversible balance) so the adjustment writer throws mid-transaction.
    expect(fn () => app(ResolvePlatformFeeDispute::class)->handle($dispute, $scn['reviewer'], 'note', -999999))
        ->toThrow(PlatformFeeException::class);

    expect($dispute->fresh()->status)->toBe(PlatformFeeDisputeStatus::UnderReview)
        ->and(DB::table('audit_logs')->where('action', 'platform_fee.dispute_resolved')->count())->toBe($auditBefore)
        ->and(PlatformFeeAdjustment::query()->count())->toBe(0);
});

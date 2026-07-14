<?php

declare(strict_types=1);

namespace App\Domain\Billing\Services;

use App\Domain\Billing\Enums\CanonicalPlatformFeeTier;
use App\Domain\Billing\Enums\PlatformFeeBasisType;
use App\Domain\Billing\Exceptions\PlatformFeeException;
use App\Domain\Billing\Queries\ResolveEffectivePlatformBillingSettings;
use App\Domain\Billing\Queries\ResolveEffectivePlatformFeeConfiguration;
use App\Domain\Billing\Queries\ResolveMerchantServiceFeeTier;
use App\Domain\Billing\Queries\ResolvePlatformFeeBasis;
use App\Domain\Billing\ValueObjects\PlatformFeeFinalizationResult;
use App\Domain\Invoicing\Actions\FinalizeInvoice;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Invoicing\Models\InvoiceItem;
use App\Domain\Invoicing\ValueObjects\InvoiceTotals;
use App\Domain\Merchants\Models\Merchant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;

/**
 * Applies the percentage platform-fee snapshot to a draft invoice being finalized (Plan §51, §52;
 * Phase 20E). Called INSIDE the existing {@see FinalizeInvoice} transaction
 * on locked, server-owned data. It does NOT create an earned ledger row — ledger earning happens at
 * Finance validation ({@see RecordOriginalPlatformFeeLiability}).
 *
 * Fixed-only (or any mode without a percentage component) is a true no-op returning
 * {@see PlatformFeeFinalizationResult::inactive()}. For a percentage-bearing mode it resolves the effective
 * configuration, the merchant tier (mapping `split_tier → shared`, fail-closed), and the server-owned
 * basis; computes the integer fee + tier split; allocates per-item provenance by largest remainder;
 * writes the item provenance; and returns the header snapshot + the client-shifted delta for the caller to
 * add to the invoice total. Gate 4.2: `validated_paid_amount` is valid only with a customer_centric
 * resolved tier (fail-closed here even when a merchant tier override differs from the config default).
 */
final class RecordPlatformFeeAtFinalization
{
    public function __construct(
        private readonly ResolveEffectivePlatformBillingSettings $billingSettings,
        private readonly ResolveEffectivePlatformFeeConfiguration $configResolver,
        private readonly ResolveMerchantServiceFeeTier $tierResolver,
        private readonly ResolvePlatformFeeBasis $basisResolver,
        private readonly CalculatePlatformFee $calculator,
        private readonly AllocatePlatformFeeByLargestRemainder $allocator,
    ) {}

    /**
     * @param  Collection<int, InvoiceItem>  $items  locked, already-priced invoice items
     */
    public function apply(Invoice $invoice, Collection $items, InvoiceTotals $totals, CarbonImmutable $now): PlatformFeeFinalizationResult
    {
        $settings = $this->billingSettings->current($now);
        $mode = $settings?->billing_mode;

        // Fixed-only / no settings → inert (no config resolution, no snapshot, no shifted line, no ledger).
        if ($mode === null || ! $mode->hasPercentageComponent()) {
            return PlatformFeeFinalizationResult::inactive();
        }

        $currency = strtoupper($invoice->currency);
        $config = $this->configResolver->require($mode, $currency, $now);

        /** @var Merchant $merchant */
        $merchant = Merchant::query()->whereKey($invoice->merchant_id)->firstOrFail();
        $tier = $this->tierResolver->resolve($merchant->service_fee_tier, $config->tier_behavior);

        // Gate 4.2 — resolved-effective-tier guard (a merchant override can differ from the config default).
        if ($config->fee_basis_type === PlatformFeeBasisType::ValidatedPaidAmount && $tier !== CanonicalPlatformFeeTier::CustomerCentric) {
            throw PlatformFeeException::validatedPaidRequiresCustomerCentric($tier->value);
        }

        // The mode-shape DB CHECK guarantees these are set for percentage modes; guard defensively so a
        // corrupt configuration fails closed rather than mispricing.
        $basisType = $config->fee_basis_type;
        if ($basisType === null || $config->percentage_basis_points === null) {
            throw PlatformFeeException::missingConfiguration($mode->value, $currency, $now->toDateString());
        }

        $basisAmount = $this->basisResolver->invoiceLevelAmount($basisType, $totals);
        $rate = $config->percentage_basis_points;

        $fee = $this->calculator->calculate($basisAmount, $rate, $tier, $config->shared_split_basis_points, $currency);

        // Per-item provenance by largest remainder (weighted by line total; deterministic ULID tie-break).
        $weights = [];
        foreach ($items as $item) {
            $weights[$item->ulid] = (int) $item->line_total_minor;
        }
        $allocations = $this->allocator->allocateFee($fee, $weights);
        $byUlid = [];
        foreach ($allocations as $allocation) {
            $byUlid[$allocation->key] = $allocation;
        }
        foreach ($items as $item) {
            $allocation = $byUlid[$item->ulid] ?? null;
            if ($allocation !== null) {
                $item->forceFill([
                    'platform_fee_item_gross_minor' => $allocation->grossMinor,
                    'platform_fee_item_client_shifted_minor' => $allocation->clientShiftedMinor,
                    'platform_fee_item_absorbed_minor' => $allocation->absorbedMinor,
                ])->save();
            }
        }

        return PlatformFeeFinalizationResult::active(
            configurationId: (int) $config->id,
            billingMode: $mode,
            tier: $tier,
            basisType: $basisType,
            basisAmountMinor: $basisAmount,
            rateBasisPoints: $rate,
            sharedSplitBasisPoints: $config->shared_split_basis_points,
            fee: $fee,
            resolvedAtIso: $now->toIso8601String(),
        );
    }
}

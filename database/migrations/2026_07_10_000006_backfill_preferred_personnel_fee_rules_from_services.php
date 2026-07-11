<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Legacy preferred-personnel-fee expand-and-contract backfill (Plan §13.10; Phase 20A).
 * Data dictionary: docs/architecture/data-dictionary/billing-and-wallet.md.
 *
 * Seeds one `fixed_amount`, `service`-scoped, `active` rule per service whose legacy
 * `services.preferred_personnel_fee_minor` is NON-NULL (including 0). The migrated
 * `fixed_amount_minor` equals the legacy minor-unit value EXACTLY; currency follows the
 * service currency; basis is `service_item_net_amount`.
 *
 * Cutover is the immutable, product-owner-fixed literal DATE '2026-07-10' (never now()/today()/
 * CURRENT_DATE — deterministic across every environment). This is PROSPECTIVE: Phase 17 changed
 * only FUTURE finalization; already-finalized invoices keep their fee snapshot and are never
 * recalculated (the resolver swap in AppServiceProvider does not touch issued invoices).
 *
 * `created_by` is NULL (system/migration; no acting user). The legacy column is retained,
 * read-only through application paths, and is NOT dropped in this deploy (contract step and its
 * removal owner are deferred to a later authorized migration with a compatibility proof).
 * Forward-only (ADR-004). Idempotent: skips a service that already has an active service rule
 * effective on the cutover date.
 */
return new class extends Migration
{
    private const CUTOVER = '2026-07-10';

    private const REASON = 'Phase 20A legacy preferred-personnel-fee backfill';

    public function up(): void
    {
        DB::table('services')
            ->whereNotNull('preferred_personnel_fee_minor')
            ->orderBy('id')
            ->chunkById(500, function ($services): void {
                foreach ($services as $service) {
                    $alreadyBackfilled = DB::table('preferred_personnel_fee_rules')
                        ->where('scope', 'service')
                        ->where('service_id', $service->id)
                        ->where('status', 'active')
                        ->where('effective_from', self::CUTOVER)
                        ->exists();

                    if ($alreadyBackfilled) {
                        continue;
                    }

                    DB::table('preferred_personnel_fee_rules')->insert([
                        'ulid' => (string) Str::ulid(),
                        'calculation_type' => 'fixed_amount',
                        'fixed_amount_minor' => $service->preferred_personnel_fee_minor,
                        'percentage_basis_points' => null,
                        'currency' => $service->currency,
                        'calculation_basis' => 'service_item_net_amount',
                        'scope' => 'service',
                        'service_id' => $service->id,
                        'effective_from' => self::CUTOVER,
                        'effective_to' => null,
                        'status' => 'active',
                        'created_by' => null,
                        'approved_by' => null,
                        'approved_at' => null,
                        'change_reason' => self::REASON,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            });
    }

    public function down(): void
    {
        // Local/CI convenience only (forward-only production posture): remove exactly the
        // backfilled rows by their deterministic marker.
        DB::table('preferred_personnel_fee_rules')
            ->where('change_reason', self::REASON)
            ->where('effective_from', self::CUTOVER)
            ->delete();
    }
};

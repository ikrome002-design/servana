<?php

declare(strict_types=1);

namespace App\Domain\Billing\Queries;

use App\Domain\Messaging\Sms\Models\SmsBillingEntry;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

/**
 * Platform SMS usage aggregation (COR-UI08-001 §9; Phase UI-08).
 *
 * NO TENANCY ESCAPE HATCH IS USED OR NEEDED. `MerchantScope` filters only when a merchant is
 * resolved, and `ResolvePlatformContext` binds none — a platform route therefore reads across
 * merchants naturally, without `withoutTenancy()`, so tenant isolation stays intact for every
 * other caller of the same model.
 *
 * FOUR DISTINCT QUANTITIES, never conflated:
 *   - message count    campaigns sent (one authored message per campaign)
 *   - recipient count  people addressed
 *   - billable units   segments x recipients — the charging basis
 *   - amount           integer minor units, ex-tax (ADR-005)
 *
 * PRIVACY: aggregates only. No recipient row, no phone number in any form, no message body, no
 * contact export. The join reaches `personnel_sms_campaigns` for its COUNT columns and nothing
 * else — the encrypted body and the recipient table are never touched.
 *
 * Months are bucketed in `Africa/Nairobi`, the business-day zone, while storage stays UTC.
 */
final class SmsBillingUsageProjection
{
    /**
     * Rows are mapped to a documented array shape here rather than handed out as raw query
     * objects, so every consumer sees the same typed contract and no caller has to know the
     * SELECT aliases.
     *
     * @param  array{merchant_id?:int|null,from?:CarbonImmutable|null,to?:CarbonImmutable|null}  $filters
     * @return LengthAwarePaginator<int, array{usage_month:string,merchant_id:int,branch_id:int,currency:string,message_count:int,recipient_count:int,billable_units:int,amount_minor:int}>
     */
    public function paginate(array $filters = [], int $perPage = 25): LengthAwarePaginator
    {
        $page = $this->query($filters)
            ->orderByDesc('usage_month')
            ->orderBy('merchant_id')
            ->orderBy('branch_id')
            ->paginate($perPage);

        // Each row is cast to an array before it is read: a query-builder row is a bare stdClass
        // whose SELECT aliases no static analyser can know, and inventing a pseudo-type for it
        // would assert a shape rather than prove one.
        /** @var LengthAwarePaginator<int, array{usage_month:string,merchant_id:int,branch_id:int,currency:string,message_count:int,recipient_count:int,billable_units:int,amount_minor:int}> $mapped */
        $mapped = $page->through(static function (object $row): array {
            /** @var array<string, mixed> $values */
            $values = (array) $row;

            return [
                'usage_month' => (string) ($values['usage_month'] ?? ''),
                'merchant_id' => (int) ($values['merchant_id'] ?? 0),
                'branch_id' => (int) ($values['branch_id'] ?? 0),
                'currency' => (string) ($values['currency'] ?? ''),
                'message_count' => (int) ($values['message_count'] ?? 0),
                'recipient_count' => (int) ($values['recipient_count'] ?? 0),
                'billable_units' => (int) ($values['billable_units'] ?? 0),
                'amount_minor' => (int) ($values['amount_minor'] ?? 0),
            ];
        });

        return $mapped;
    }

    /**
     * Total billable units in the calendar month containing `$at` — the figure the configured
     * warning threshold is compared against.
     */
    public function billableUnitsForMonth(CarbonImmutable $at): int
    {
        $start = $at->setTimezone('Africa/Nairobi')->startOfMonth();
        $end = $start->addMonth();

        return (int) SmsBillingEntry::query()
            ->whereIn('status', ['billable', 'invoiced'])
            ->where('created_at', '>=', $start)
            ->where('created_at', '<', $end)
            ->sum('quantity');
    }

    /**
     * @param  array{merchant_id?:int|null,from?:CarbonImmutable|null,to?:CarbonImmutable|null}  $filters
     */
    private function query(array $filters): QueryBuilder
    {
        $query = DB::table('sms_billing_entries')
            ->join('personnel_sms_campaigns', 'personnel_sms_campaigns.id', '=', 'sms_billing_entries.campaign_id')
            ->select([
                DB::raw("date_trunc('month', sms_billing_entries.created_at AT TIME ZONE 'Africa/Nairobi') as usage_month"),
                'sms_billing_entries.merchant_id',
                'sms_billing_entries.branch_id',
                'sms_billing_entries.currency',
                DB::raw('count(distinct sms_billing_entries.campaign_id) as message_count'),
                DB::raw('coalesce(sum(personnel_sms_campaigns.recipient_count), 0) as recipient_count'),
                DB::raw('coalesce(sum(sms_billing_entries.quantity), 0) as billable_units'),
                DB::raw('coalesce(sum(sms_billing_entries.amount_minor), 0) as amount_minor'),
            ])
            ->whereIn('sms_billing_entries.status', ['billable', 'invoiced'])
            ->groupBy(
                DB::raw("date_trunc('month', sms_billing_entries.created_at AT TIME ZONE 'Africa/Nairobi')"),
                'sms_billing_entries.merchant_id',
                'sms_billing_entries.branch_id',
                'sms_billing_entries.currency',
            );

        if (($filters['merchant_id'] ?? null) !== null) {
            $query->where('sms_billing_entries.merchant_id', $filters['merchant_id']);
        }

        if (($filters['from'] ?? null) !== null) {
            $query->where('sms_billing_entries.created_at', '>=', $filters['from']);
        }

        if (($filters['to'] ?? null) !== null) {
            $query->where('sms_billing_entries.created_at', '<', $filters['to']);
        }

        return $query;
    }
}

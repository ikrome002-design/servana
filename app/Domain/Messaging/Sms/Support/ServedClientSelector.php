<?php

declare(strict_types=1);

namespace App\Domain\Messaging\Sms\Support;

use App\Domain\Clients\Enums\ClientStatus;
use App\Domain\Clients\Models\Client;
use App\Domain\Hr\Models\StaffProfile;
use App\Domain\Scheduling\Enums\ServiceSessionStatus;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * THE authoritative definition of "a client this Personnel user personally served"
 * (Plan §64: *"personnel opens served-clients view (own served clients only …)"*; §80 Phase 21S;
 * ADR-010; Phase 21S).
 *
 * A client is served by a staff profile when that staff profile has **at least one COMPLETED
 * service session** with the client, inside the acting merchant and inside the acting membership's
 * branch scope. Nothing else qualifies — an appointment, a queue entry, an in-progress session or
 * a cancelled session all count for nothing, because §64 says "completed at least one service
 * session" and the queue/appointment tables are provenance, not delivery.
 *
 * SECURITY PROPERTIES (each one is a test in Phase21SServedClientSelectorTest):
 *   - `staff_profile_id` is supplied by the caller from the AUTHENTICATED membership; this class
 *     has no code path that accepts a client-supplied staff identifier.
 *   - The `EXISTS` sub-query pins `merchant_id` and the branch scope EXPLICITLY, because a raw
 *     sub-query does not inherit the ServiceSession global scopes.
 *   - `Client::query()` additionally carries the BelongsToMerchant + BelongsToBranch global scopes,
 *     so cross-tenant and cross-branch rows are unreachable twice over.
 *   - Search is allowlisted to the client NAME. Searching by phone is deliberately impossible:
 *     it would turn this endpoint into an oracle that confirms whether a guessed number belongs to
 *     a client (ADR-010, Plan §73 "personnel contact extraction").
 *   - `%` and `_` are escaped, so a search term cannot widen its own LIKE pattern.
 *
 * The selector NEVER selects `phone_encrypted`, `phone_index` or `email_encrypted` — callers get
 * the model with those columns `$hidden`, and the Resource renders `phone_last_four` only.
 */
final class ServedClientSelector
{
    public function __construct(private readonly TenantContext $context) {}

    /**
     * Base query of clients this staff profile personally served, already merchant- and
     * branch-constrained.
     *
     * @return Builder<Client>
     */
    public function query(StaffProfile $profile): Builder
    {
        $merchantId = $this->context->merchantId();
        $branchIds = $this->context->branchIds();

        return Client::query()
            ->whereExists(function ($sub) use ($profile, $merchantId, $branchIds): void {
                $sub->select(DB::raw('1'))
                    ->from('service_sessions')
                    ->whereColumn('service_sessions.client_id', 'clients.id')
                    // Own scope: only sessions this staff profile personally performed.
                    ->where('service_sessions.staff_profile_id', $profile->id)
                    // Only a COMPLETED session evidences service delivery (Plan §64).
                    ->where('service_sessions.status', ServiceSessionStatus::Completed->value)
                    // Explicit tenancy: a raw sub-query does not inherit the model global scopes.
                    ->where('service_sessions.merchant_id', $merchantId);

                if ($branchIds !== []) {
                    $sub->whereIn('service_sessions.branch_id', $branchIds);
                }
            });
    }

    /**
     * Served clients available for SMS: active only (an archived client is excluded from the list
     * outright, and is reported as `client_archived` if it is selected by ULID).
     *
     * @return Builder<Client>
     */
    public function availableForSms(StaffProfile $profile, ?string $search = null): Builder
    {
        $query = $this->query($profile)->where('status', ClientStatus::Active->value);

        $term = is_string($search) ? trim($search) : '';

        if ($term !== '') {
            // Allowlisted to the NAME only, with LIKE metacharacters escaped so the term cannot
            // widen its own pattern. There is no phone/email search path by design (ADR-010).
            $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term);
            $query->where('full_name', 'ilike', '%'.$escaped.'%');
        }

        return $query;
    }

    /**
     * The latest completed session this staff profile performed for EACH of the given clients — the
     * evidence stored on each recipient snapshot. A client absent from the returned map has no such
     * session, which is exactly the `not_served` condition.
     *
     * Deliberately ONE grouped query rather than a lookup per client: a campaign may select up to
     * the configured batch cap, and Plan §72 prohibits N+1 on list paths.
     *
     * @param  list<int>  $clientIds
     * @return array<int, int> client id => service session id
     */
    public function evidencingSessionIds(StaffProfile $profile, array $clientIds): array
    {
        if ($clientIds === []) {
            return [];
        }

        $merchantId = $this->context->merchantId();
        $branchIds = $this->context->branchIds();

        $query = DB::table('service_sessions')
            ->selectRaw('client_id, max(id) as session_id')
            ->whereIn('client_id', $clientIds)
            ->where('staff_profile_id', $profile->id)
            ->where('status', ServiceSessionStatus::Completed->value)
            ->where('merchant_id', $merchantId);

        if ($branchIds !== []) {
            $query->whereIn('branch_id', $branchIds);
        }

        $map = [];

        foreach ($query->groupBy('client_id')->get() as $row) {
            $map[(int) $row->client_id] = (int) $row->session_id;
        }

        return $map;
    }

    /**
     * The latest completed session for a single client, or null when this staff profile never
     * served them.
     */
    public function evidencingSessionId(StaffProfile $profile, int $clientId): ?int
    {
        return $this->evidencingSessionIds($profile, [$clientId])[$clientId] ?? null;
    }
}

<?php

declare(strict_types=1);

namespace App\Domain\Search\Definitions;

use App\Domain\Clients\Models\Client;
use App\Domain\Hr\Models\StaffProfile;
use App\Domain\Messaging\Sms\Support\ServedClientSelector;
use App\Domain\Search\DTO\SearchContext;
use App\Domain\Search\DTO\SearchResultItem;
use App\Domain\Search\Enums\SearchDocumentType;
use App\Domain\Search\Support\SearchLikeTerm;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * `served_client` — PERSONNEL OWN-SCOPE search over clients this staff member personally served
 * (Phase 22; decision D-22-06; Plan §64; ADR-010).
 *
 * NOT INDEXED. Indexing this would require a derived `served_by_staff_profile_ids` array on the
 * client document — high-churn data whose staleness would be an own-scope LEAK (a client indexed
 * against the wrong staff profile is a cross-personnel disclosure). So this type never touches
 * Meilisearch: it delegates entirely to the Phase 21S {@see ServedClientSelector}, which is already
 * the authoritative, tested definition of "personally served" (at least one COMPLETED service
 * session performed by this staff profile, with `merchant_id` and the branch scope pinned explicitly
 * inside the `EXISTS` sub-query because a raw sub-query does not inherit the model global scopes).
 *
 * The staff profile is derived from the AUTHENTICATED membership and is never accepted from the
 * request — the same rule the 21S served-clients endpoint enforces.
 *
 * NAME ONLY, and no contact whatsoever. Searching by phone here is deliberately impossible: it
 * would turn the endpoint into an oracle confirming whether a guessed number belongs to a client
 * this member served (Plan §73 "personnel contact extraction"). The 21S screen shows a masked
 * phone; search shows none at all, because {@see SearchResultItem} has no contact field
 * (decision D-22-03).
 *
 * @extends AbstractSearchDocumentDefinition<Client>
 */
final class ServedClientSearchDefinition extends AbstractSearchDocumentDefinition
{
    public function __construct(private readonly ServedClientSelector $servedClients) {}

    public function type(): SearchDocumentType
    {
        return SearchDocumentType::ServedClient;
    }

    /** Never indexed — see the class docblock. */
    public function indexName(): null
    {
        return null;
    }

    public function modelClass(): string
    {
        return Client::class;
    }

    public function canSearch(SearchContext $context): bool
    {
        // The 21S read permission, which is `allow_read` in billing read-only: a merchant in grace
        // can still SEE served clients; only SENDING is blocked.
        return $context->can('personnel.my_served_clients.view') && $context->staffProfileId !== null;
    }

    protected function table(): string
    {
        return 'clients';
    }

    protected function baseQuery(SearchContext $context): Builder
    {
        $profile = $this->actingProfile($context);

        if ($profile === null) {
            // No staff profile ⇒ no served clients. An impossible predicate, never an unscoped query.
            return Client::query()->whereRaw('1 = 0');
        }

        return $this->servedClients->availableForSms($profile)
            ->where('clients.merchant_id', $context->merchantId)
            ->whereIn('clients.branch_id', $context->branchIds);
    }

    protected function applyTextMatch(Builder $query, string $term): void
    {
        // NAME ONLY, with LIKE metacharacters escaped so the term cannot widen its own pattern —
        // the same rule (and the same shared escaper) as the Phase 21S served-client list. There is
        // no phone or email branch here by design.
        $query->where('clients.full_name', 'ilike', SearchLikeTerm::contains($term));
    }

    /** @return list<string> */
    protected function resultRelations(): array
    {
        return ['branch'];
    }

    /**
     * Own-scope IS the authorization here, and it is already enforced in SQL by the 21S selector's
     * `EXISTS` sub-query (own staff profile + COMPLETED session + explicit merchant/branch pins),
     * plus the `BelongsToMerchant`/`BelongsToBranch` global scopes on `Client`.
     *
     * `ClientPolicy::view` is deliberately NOT used: it requires `client.view`, which a Personnel
     * member does not hold — using it would silently return zero results and hide a real capability
     * behind the wrong gate. The defensive re-check below restates the tenancy invariant so a
     * future refactor of the selector cannot quietly widen this type.
     *
     * @param  Client  $model
     */
    protected function passesRecheck(SearchContext $context, Model $model): bool
    {
        return $model->merchant_id === $context->merchantId
            && in_array($model->branch_id, $context->branchIds, true);
    }

    /** @return array<string, mixed> */
    public function indexDocumentFor(Model $model): array
    {
        throw new RuntimeException('served_client is never indexed (decision D-22-06).');
    }

    protected function toResult(Model $model): SearchResultItem
    {
        return new SearchResultItem(
            type: $this->type(),
            ulid: $model->ulid,
            title: $model->full_name,
            subtitle: null,
            status: $model->status->value,
            date: null,
            amount: null,
            // The own-scope surface for a Personnel member is the Phase 21S screen; they hold no
            // `client.view`, so the Front-Office client detail page is not a legitimate target.
            routeName: 'personnel.sms',
            routeParamId: null,
            branchUlid: $model->branch?->ulid,
            branchName: $model->branch?->name,
        );
    }

    /** The acting user's own staff profile — derived from the membership, never from the request. */
    private function actingProfile(?SearchContext $context): ?StaffProfile
    {
        if ($context === null || $context->staffProfileId === null) {
            return null;
        }

        return StaffProfile::query()
            ->where('id', $context->staffProfileId)
            ->where('merchant_id', $context->merchantId)
            ->first();
    }
}

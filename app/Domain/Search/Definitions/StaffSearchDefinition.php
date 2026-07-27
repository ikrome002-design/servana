<?php

declare(strict_types=1);

namespace App\Domain\Search\Definitions;

use App\Domain\Hr\Models\StaffProfile;
use App\Domain\Search\DTO\SearchContext;
use App\Domain\Search\DTO\SearchResultItem;
use App\Domain\Search\Enums\SearchDocumentType;
use App\Domain\Search\Support\SearchLikeTerm;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * `staff` — branch-scoped staff search by name and role title (Phase 22).
 *
 * AUTHORITY ANCHOR. Phase 22 recorded that `StaffProfilePolicy` had no `viewAny` and that
 * `GET /api/v1/staff` performed no `authorize()` call, so it anchored on the stricter
 * `StaffProfilePolicy::manage` and flagged the unguarded list route + the `planned` `staff.view`
 * (`owning_phase: Phase 20F`, while 20F was already `verified_complete`) as PRE-EXISTING conditions
 * outside its scope.
 *
 * Phase 23 §14.1 RESOLVED both conditions: `staff.view` is active, and it now authorizes BOTH
 * `staff.index` (new `StaffProfilePolicy::viewAny`) and `staff.show`. The anchor therefore moves to
 * `staff.view` — exactly the authority governing this type's own list and detail routes, which is
 * what the catalogue rule requires. This TIGHTENS the type: a Merchant Admin holding only the legacy
 * `branches.manage_users_lifecycle` can no longer open `hr.staff-profile` (the `routeName` every
 * result links to), so it must not receive staff results either — search must never be a wider
 * surface than the page it points at. `passesRecheck()` calls the same `view` ability, so the gate
 * and the per-record check are now provably the same authority.
 *
 * CONTACT PROTECTION. `staff_profiles.phone` is a PLAINTEXT column and `StaffProfileResource`
 * returns it. Search deliberately does neither: `phone` is not indexed, not searchable and not
 * returned (decision D-22-03). `profile_photo_path` is excluded too.
 *
 * The branch column is `primary_branch_id`, matching the model's own `branchColumn()` override.
 *
 * @extends AbstractSearchDocumentDefinition<StaffProfile>
 */
final class StaffSearchDefinition extends AbstractSearchDocumentDefinition
{
    public function type(): SearchDocumentType
    {
        return SearchDocumentType::Staff;
    }

    public function indexName(): string
    {
        return 'staff_profiles';
    }

    public function modelClass(): string
    {
        return StaffProfile::class;
    }

    public function canSearch(SearchContext $context): bool
    {
        return $context->can('staff.view');
    }

    protected function table(): string
    {
        return 'staff_profiles';
    }

    protected function baseQuery(SearchContext $context): Builder
    {
        return StaffProfile::query()
            ->where('staff_profiles.merchant_id', $context->merchantId)
            ->whereIn('staff_profiles.primary_branch_id', $context->branchIds);
    }

    protected function applyTextMatch(Builder $query, string $term): void
    {
        $pattern = SearchLikeTerm::contains($term);

        $query->where(static function (Builder $inner) use ($pattern): void {
            $inner->where('staff_profiles.display_name', 'ilike', $pattern)
                ->orWhere('staff_profiles.first_name', 'ilike', $pattern)
                ->orWhere('staff_profiles.last_name', 'ilike', $pattern)
                ->orWhere('staff_profiles.role_title', 'ilike', $pattern);
        });
    }

    /** @return list<string> */
    protected function resultRelations(): array
    {
        return ['primaryBranch'];
    }

    /** @return list<string> */
    public function indexRelations(): array
    {
        return [];
    }

    /** @return array<string, mixed> */
    public function indexDocumentFor(Model $model): array
    {
        if (! $model instanceof StaffProfile) {
            throw new RuntimeException('StaffSearchDefinition can only index a StaffProfile.');
        }

        return [
            'id' => $model->ulid,
            'merchant_id' => $model->merchant_id,
            'branch_id' => $model->primary_branch_id,
            'display_name' => $model->display_name,
            'first_name' => $model->first_name,
            'last_name' => $model->last_name,
            'role_title' => $model->role_title,
        ];
    }

    protected function toResult(Model $model): SearchResultItem
    {
        return new SearchResultItem(
            type: $this->type(),
            ulid: $model->ulid,
            title: $model->display_name,
            subtitle: $model->role_title,
            status: $model->employment_status->value,
            date: null,
            amount: null,
            routeName: 'hr.staff-profile',
            routeParamId: $model->ulid,
            branchUlid: $model->primaryBranch?->ulid,
            branchName: $model->primaryBranch?->name,
        );
    }
}

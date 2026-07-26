<?php

declare(strict_types=1);

namespace App\Domain\Search\Definitions;

use App\Domain\Scheduling\Models\Appointment;
use App\Domain\Search\DTO\SearchContext;
use App\Domain\Search\DTO\SearchResultItem;
use App\Domain\Search\Enums\SearchDocumentType;
use App\Domain\Search\Support\SearchLikeTerm;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * `appointment` — branch-scoped appointment search by reference, client name and service name
 * (Phase 22).
 *
 * Authority is `AppointmentPolicy::viewAny` (`appointment.view` OR `branch.dashboard.view`), the
 * same disjunction the live appointment list and detail routes use — a Branch Manager with
 * dashboard visibility can search appointments exactly as they can already read them.
 *
 * Indexing the client's NAME is not a new exposure: `AppointmentResource` already returns
 * `client.full_name` (plus masked phone and last four) to every caller authorized for this type.
 * Search returns strictly less — the name only, with NO contact field at all (decision D-22-03) —
 * and the free-text operator fields (`cancellation_reason`, `transfer_reason`) are neither indexed
 * nor returned.
 *
 * @extends AbstractSearchDocumentDefinition<Appointment>
 */
final class AppointmentSearchDefinition extends AbstractSearchDocumentDefinition
{
    public function type(): SearchDocumentType
    {
        return SearchDocumentType::Appointment;
    }

    public function indexName(): string
    {
        return 'appointments';
    }

    public function modelClass(): string
    {
        return Appointment::class;
    }

    public function canSearch(SearchContext $context): bool
    {
        return $context->can('appointment.view') || $context->can('branch.dashboard.view');
    }

    protected function table(): string
    {
        return 'appointments';
    }

    protected function baseQuery(SearchContext $context): Builder
    {
        return Appointment::query()
            ->where('appointments.merchant_id', $context->merchantId)
            ->whereIn('appointments.branch_id', $context->branchIds);
    }

    protected function applyTextMatch(Builder $query, string $term): void
    {
        $pattern = SearchLikeTerm::contains($term);

        $query->where(function (Builder $inner) use ($pattern): void {
            $inner->where('appointments.ulid', 'ilike', $pattern)
                ->orWhereHas('client', function (Builder $client) use ($pattern): void {
                    $client->where('clients.full_name', 'ilike', $pattern);
                })
                ->orWhereHas('service', function (Builder $service) use ($pattern): void {
                    $service->where('services.name', 'ilike', $pattern);
                });
        });
    }

    /** @return list<string> */
    protected function resultRelations(): array
    {
        return ['branch', 'client', 'service'];
    }

    /** @return list<string> */
    public function indexRelations(): array
    {
        return ['client', 'service'];
    }

    /** @return array<string, mixed> */
    public function indexDocumentFor(Model $model): array
    {
        if (! $model instanceof Appointment) {
            throw new RuntimeException('AppointmentSearchDefinition can only index an Appointment.');
        }

        return [
            'id' => $model->ulid,
            'merchant_id' => $model->merchant_id,
            'branch_id' => $model->branch_id,
            'reference' => $model->ulid,
            'client_name' => $model->client?->full_name,
            'service_name' => $model->service?->name,
        ];
    }

    protected function toResult(Model $model): SearchResultItem
    {
        return new SearchResultItem(
            type: $this->type(),
            ulid: $model->ulid,
            title: $model->service->name ?? 'Appointment',
            subtitle: $model->client?->full_name,
            status: $model->status->value,
            date: $model->starts_at->toIso8601String(),
            amount: null,
            routeName: 'front-office.appointments.detail',
            routeParamId: $model->ulid,
            branchUlid: $model->branch?->ulid,
            branchName: $model->branch?->name,
        );
    }
}

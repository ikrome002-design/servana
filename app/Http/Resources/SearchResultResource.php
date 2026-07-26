<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Search\DTO\SearchResultItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One search result (Phase 22; decision D-22-03).
 *
 * The key set below IS the published response schema, and it contains NO contact field of any kind —
 * no `phone`, `phone_masked`, `phone_last_four`, `email` or `email_masked` — not conditionally, not
 * per role, not ever. Several of the underlying canonical Resources (`AppointmentResource`,
 * `QueueEntryResource`, `ServiceSessionResource`, `InvoiceResource`) DO return masked client contact
 * today; search deliberately returns less, so contact protection is a property of the type rather
 * than a per-branch condition (ADR-010; Plan §19.4, §74).
 *
 * There is likewise no provider reference, no integration payload, no audit before/after value and no
 * key material. `snippet` may only be populated from a field the catalogue declares searchable, and
 * no such field is a contact column.
 *
 * `can` is deliberately absent: a result is already proven viewable (every item passed the policy its
 * own detail route uses), and search grants no other ability.
 *
 * @mixin SearchResultItem
 */
final class SearchResultResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var SearchResultItem $item */
        $item = $this->resource;

        return [
            'type' => $item->type->value,
            'type_label' => $item->type->label(),
            'ulid' => $item->ulid,
            'title' => $item->title,
            'subtitle' => $item->subtitle,
            'snippet' => $item->snippet,
            'status' => $item->status,
            'date' => $item->date,
            // An explicit `=== null ? null :` ternary, not `?->`: the OpenAPI generator infers
            // nullability from the ternary but not through the nullsafe operator, so `?->` would
            // publish this non-nullable (the Phase 20F DEF-20F-015 lesson).
            'amount' => $item->amount === null ? null : $item->amount->toArray(),
            'route' => [
                'name' => $item->routeName,
                'id' => $item->routeParamId,
            ],
            'branch' => $item->branchUlid === null ? null : [
                'ulid' => $item->branchUlid,
                'name' => $item->branchName,
            ],
        ];
    }
}

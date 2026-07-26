<?php

declare(strict_types=1);

namespace App\Domain\Search\DTO;

use App\Domain\Search\Enums\SearchDocumentType;
use App\Support\Money;

/**
 * One safe, already-authorized search result (Phase 22; decision D-22-03).
 *
 * The property list IS the response schema, and it deliberately has **no contact field of any
 * kind** — no `phone`, `phone_masked`, `phone_last_four`, `email` or `email_masked` — even though
 * several of the underlying canonical Resources (`AppointmentResource`, `QueueEntryResource`,
 * `ServiceSessionResource`, `InvoiceResource`) do return masked client contact today. Making the
 * absence a property of the *type* rather than a per-branch condition is what turns the
 * contact-protection invariant into something a single test can prove for every type at once
 * (ADR-010; Plan §19.4, §74).
 *
 * There is likewise no provider reference, no integration payload, no audit before/after value and
 * no secret. `snippet` may only ever be filled from a field the catalogue declares searchable, and
 * no such field is a contact column.
 *
 * An instance is only ever constructed AFTER the record has passed its own detail-route policy, so
 * holding one is itself evidence of authorization.
 */
final readonly class SearchResultItem
{
    /**
     * `$routeParamId` is the single route parameter every target route takes (`:id`), or null for a
     * list-only target such as `front-office.sessions`. Modelled as one nullable string rather than
     * a params map on purpose: an `array<string, string>` publishes as an untyped array in the
     * generated contract, whereas this yields an exact `{ name: string, id: string | null }` for the
     * SPA. Every current target route takes `:id` or nothing.
     */
    public function __construct(
        public SearchDocumentType $type,
        public string $ulid,
        public string $title,
        public ?string $subtitle,
        public ?string $status,
        public ?string $date,
        public ?Money $amount,
        public string $routeName,
        public ?string $routeParamId,
        public ?string $branchUlid,
        public ?string $branchName,
        public ?string $snippet = null,
    ) {}
}

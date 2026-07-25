<?php

declare(strict_types=1);

namespace App\Domain\Search\Services;

use App\Domain\Search\Contracts\SearchDocumentDefinition;
use App\Domain\Search\Definitions\AppointmentSearchDefinition;
use App\Domain\Search\Definitions\ClientSearchDefinition;
use App\Domain\Search\Definitions\InvoiceSearchDefinition;
use App\Domain\Search\Definitions\QueueEntrySearchDefinition;
use App\Domain\Search\Definitions\ReceiptSearchDefinition;
use App\Domain\Search\Definitions\ServedClientSearchDefinition;
use App\Domain\Search\Definitions\ServiceSessionSearchDefinition;
use App\Domain\Search\Definitions\StaffSearchDefinition;
use App\Domain\Search\DTO\SearchContext;
use App\Domain\Search\Enums\SearchDocumentType;
use Illuminate\Database\Eloquent\Model;

/**
 * The executable form of `docs/architecture/search/search-catalogue.md` §3 (Phase 22).
 *
 * FAIL-CLOSED in three ways:
 *   - a type with no registered definition is UNKNOWN and cannot be searched at all (the Form
 *     Request rejects it as 422 before we get here, and {@see for()} returns null if it ever did);
 *   - a type the caller lacks authority for is SILENTLY EXCLUDED, never 403 — a 403 would confirm
 *     which types exist and who can reach them (decision D-22-01);
 *   - requesting no type searches only the types the caller is already authorized for, so the
 *     default is the narrowest correct answer rather than "everything".
 *
 * Registration order is result order, so the surface is deterministic: the types an operator reaches
 * for most often (clients, then people, then today's work, then money) come first.
 */
final class SearchDocumentCatalogue
{
    /** @var array<string, SearchDocumentDefinition> */
    private array $definitions;

    public function __construct(
        ClientSearchDefinition $clients,
        StaffSearchDefinition $staff,
        AppointmentSearchDefinition $appointments,
        QueueEntrySearchDefinition $queueEntries,
        ServiceSessionSearchDefinition $serviceSessions,
        InvoiceSearchDefinition $invoices,
        ReceiptSearchDefinition $receipts,
        ServedClientSearchDefinition $servedClients,
    ) {
        $this->definitions = [];

        foreach ([
            $clients,
            $staff,
            $appointments,
            $queueEntries,
            $serviceSessions,
            $invoices,
            $receipts,
            $servedClients,
        ] as $definition) {
            $this->definitions[$definition->type()->value] = $definition;
        }
    }

    /** @return list<SearchDocumentDefinition> */
    public function all(): array
    {
        return array_values($this->definitions);
    }

    /**
     * Definitions that have a Meilisearch index (excludes `served_client`).
     *
     * @return list<SearchDocumentDefinition>
     */
    public function indexed(): array
    {
        return array_values(array_filter(
            $this->definitions,
            static fn (SearchDocumentDefinition $definition): bool => $definition->indexName() !== null,
        ));
    }

    public function for(SearchDocumentType $type): ?SearchDocumentDefinition
    {
        return $this->definitions[$type->value] ?? null;
    }

    /**
     * Locate the definition that owns a model class, for the indexing path.
     *
     * @param  class-string<Model>  $modelClass
     */
    public function forModel(string $modelClass): ?SearchDocumentDefinition
    {
        foreach ($this->definitions as $definition) {
            if ($definition->indexName() !== null && $definition->modelClass() === $modelClass) {
                return $definition;
            }
        }

        return null;
    }

    /** @return list<SearchDocumentType> */
    public function types(): array
    {
        return array_values(array_map(
            static fn (SearchDocumentDefinition $definition): SearchDocumentType => $definition->type(),
            $this->definitions,
        ));
    }

    /**
     * The types this caller may actually search, in catalogue order.
     *
     * @param  list<SearchDocumentType>  $requested  empty ⇒ every catalogue type is a candidate
     * @return list<SearchDocumentDefinition>
     */
    public function effectiveFor(SearchContext $context, array $requested = []): array
    {
        $wanted = array_map(static fn (SearchDocumentType $type): string => $type->value, $requested);

        $effective = [];

        foreach ($this->definitions as $key => $definition) {
            if ($wanted !== [] && ! in_array($key, $wanted, true)) {
                continue;
            }

            if (! $definition->canSearch($context)) {
                continue;
            }

            $effective[] = $definition;
        }

        return $effective;
    }

    /**
     * Every model class the catalogue indexes — used by the integration-exclusion test to prove no
     * `Searchable` model resolves to an integration table.
     *
     * @return list<class-string<Model>>
     */
    public function indexedModelClasses(): array
    {
        return array_map(
            static fn (SearchDocumentDefinition $definition): string => $definition->modelClass(),
            $this->indexed(),
        );
    }
}

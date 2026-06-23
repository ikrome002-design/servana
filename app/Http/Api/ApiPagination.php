<?php

declare(strict_types=1);

namespace App\Http\Api;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rule;

/**
 * Reusable pagination/sort contract for API collection endpoints (Plan §23, §24.2).
 *
 * The single source of truth for the project-wide pagination contract: default
 * page size 25, hard maximum 100 (or a lower documented domain cap), allowlisted
 * sort fields only, and stable deterministic ordering via a tiebreaker column.
 * Over-limit `per_page` is rejected with 422 (validation), never silently
 * unbounded and never clamped — consistent with the existing audit-log endpoints.
 *
 * Feature phases consume {@see rules()} / {@see sortRule()} in their index Form
 * Requests and {@see perPage()} / {@see applySort()} in their controllers, rather
 * than re-deriving pagination behaviour per endpoint.
 */
final class ApiPagination
{
    public const DEFAULT_PER_PAGE = 25;

    public const MAX_PER_PAGE = 100;

    /**
     * Validation rules for the `per_page` parameter (1..$max, integer).
     *
     * @return array<string, list<mixed>>
     */
    public static function rules(int $max = self::MAX_PER_PAGE): array
    {
        return ['per_page' => ['sometimes', 'integer', 'min:1', 'max:'.$max]];
    }

    /**
     * Validation rule for an allowlisted `sort` parameter. Each allowed token is a
     * column name with an optional leading `-` for descending order.
     *
     * @param  list<string>  $allowed
     * @return array<string, list<mixed>>
     */
    public static function sortRule(array $allowed): array
    {
        return ['sort' => ['sometimes', 'string', Rule::in($allowed)]];
    }

    /**
     * Resolve the validated page size, clamped defensively to the contract bounds
     * (validation already rejects out-of-range input with 422).
     *
     * @param  array<string, mixed>  $validated
     */
    public static function perPage(array $validated, int $default = self::DEFAULT_PER_PAGE, int $max = self::MAX_PER_PAGE): int
    {
        $perPage = (int) ($validated['per_page'] ?? $default);

        return max(1, min($perPage, $max));
    }

    /**
     * Apply an allowlisted sort with a stable tiebreaker so ordering is
     * deterministic across equal sort keys and pages.
     *
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     */
    public static function applySort(Builder $query, ?string $sort, string $default, string $tiebreaker = 'id'): void
    {
        $sort = is_string($sort) && $sort !== '' ? $sort : $default;
        $direction = str_starts_with($sort, '-') ? 'desc' : 'asc';
        $column = ltrim($sort, '-');

        $query->orderBy($column, $direction);

        if ($column !== $tiebreaker) {
            $query->orderByDesc($tiebreaker);
        }
    }
}

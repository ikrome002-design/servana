<?php

declare(strict_types=1);

namespace App\Http\Resources\Platform;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Merchant-lifecycle section of the Super Administrator dashboard (Phase UI-08 §5.4.1).
 *
 * Each dashboard section is its own Resource for a measured reason: the OpenAPI generator cannot
 * express a NESTED `array{...}` shape — given one it publishes the section as `string`, and given a
 * `@phpstan-import-type` alias it publishes an empty object. A nested Resource, however, resolves
 * to a proper `$ref` (the same mechanism that fixed UI08-API-001). Six small FLAT resources
 * therefore produce a correct contract where one large nested docblock could not.
 *
 * Operational status and billing status stay SEPARATE fields. Merging them is the exact conflation
 * the merchant screens are required to avoid.
 *
 * @property-read array<string,mixed> $resource
 */
final class PlatformDashboardLifecycleResource extends JsonResource
{
    /**
     * @return array{
     *     availability:string, gate:string|null, as_of:string, total_merchants:int,
     *     by_operational_status:array<string,int>, by_billing_status:array<string,int>,
     *     billing_suspended:int, active_branches:int, definitions:array<string,string>,
     *     time_range:string, drill_through:string
     * }
     */
    public function toArray(Request $request): array
    {
        $section = $this->resource;

        return [
            'availability' => (string) $section['availability'],
            'gate' => $section['gate'] === null ? null : (string) $section['gate'],
            'as_of' => (string) $section['as_of'],
            'total_merchants' => (int) $section['total_merchants'],
            'by_operational_status' => (array) $section['by_operational_status'],
            'by_billing_status' => (array) $section['by_billing_status'],
            'billing_suspended' => (int) $section['billing_suspended'],
            'active_branches' => (int) $section['active_branches'],
            'definitions' => (array) $section['definitions'],
            'time_range' => (string) $section['time_range'],
            'drill_through' => (string) $section['drill_through'],
        ];
    }
}

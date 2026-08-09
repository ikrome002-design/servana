<?php

declare(strict_types=1);

namespace App\Http\Resources\Platform;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Audit-activity section of the Super Administrator dashboard (Phase UI-08 §5.4.1).
 *
 * Volume and severity mix over a rolling window, read from the append-only audit log. Nothing here
 * mutates or summarises away an event; the drill-through is the authority.
 *
 * See PlatformDashboardLifecycleResource for why each section is its own resource.
 *
 * @property-read array<string,mixed> $resource
 */
final class PlatformDashboardAuditResource extends JsonResource
{
    /**
     * @return array{
     *     availability:string, gate:string|null, as_of:string, events_last_7_days:int,
     *     by_severity:array<string,int>, definitions:array<string,string>,
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
            'events_last_7_days' => (int) $section['events_last_7_days'],
            'by_severity' => (array) $section['by_severity'],
            'definitions' => (array) $section['definitions'],
            'time_range' => (string) $section['time_range'],
            'drill_through' => (string) $section['drill_through'],
        ];
    }
}

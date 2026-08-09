<?php

declare(strict_types=1);

namespace App\Http\Resources\Platform;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Registration-monitoring section of the Super Administrator dashboard (Phase UI-08 §5.4.1).
 *
 * Volume and the setup-completion gap only. No risk or fraud SCORE is published, because Servana
 * records no fraud signal — a confidence number with nothing behind it would be worse than no
 * number at all. Self-registration remains the only merchant creation path; this is not an
 * approval queue.
 *
 * See PlatformDashboardLifecycleResource for why each section is its own resource.
 *
 * @property-read array<string,mixed> $resource
 */
final class PlatformDashboardRegistrationsResource extends JsonResource
{
    /**
     * @return array{
     *     availability:string, gate:string|null, as_of:string, registered_last_7_days:int,
     *     registered_last_30_days:int, awaiting_setup_completion:int,
     *     definitions:array<string,string>, time_range:string, drill_through:string
     * }
     */
    public function toArray(Request $request): array
    {
        $section = $this->resource;

        return [
            'availability' => (string) $section['availability'],
            'gate' => $section['gate'] === null ? null : (string) $section['gate'],
            'as_of' => (string) $section['as_of'],
            'registered_last_7_days' => (int) $section['registered_last_7_days'],
            'registered_last_30_days' => (int) $section['registered_last_30_days'],
            'awaiting_setup_completion' => (int) $section['awaiting_setup_completion'],
            'definitions' => (array) $section['definitions'],
            'time_range' => (string) $section['time_range'],
            'drill_through' => (string) $section['drill_through'],
        ];
    }
}

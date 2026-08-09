<?php

declare(strict_types=1);

namespace App\Http\Resources\Platform;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Governance-task section of the Super Administrator dashboard (Phase UI-08 §5.4.1).
 *
 * Only work with a REAL source appears. Reconciliation exceptions are deliberately absent rather
 * than reported as zero — they are Gate-W blocked and are named in the integrations section.
 *
 * Billing suspension and policy suspension are separate counts: a billing payment never clears a
 * policy suspension, so presenting them as one number would misdirect the whole recovery decision.
 *
 * See PlatformDashboardLifecycleResource for why each section is its own resource.
 *
 * @property-read array<string,mixed> $resource
 */
final class PlatformDashboardTasksResource extends JsonResource
{
    /**
     * @return array{
     *     availability:string, gate:string|null, merchants_suspended_for_billing:int,
     *     merchants_suspended_by_policy:int, overdue_invoices:int,
     *     definitions:array<string,string>, time_range:string, drill_through:string
     * }
     */
    public function toArray(Request $request): array
    {
        $section = $this->resource;

        return [
            'availability' => (string) $section['availability'],
            'gate' => $section['gate'] === null ? null : (string) $section['gate'],
            'merchants_suspended_for_billing' => (int) $section['merchants_suspended_for_billing'],
            'merchants_suspended_by_policy' => (int) $section['merchants_suspended_by_policy'],
            'overdue_invoices' => (int) $section['overdue_invoices'],
            'definitions' => (array) $section['definitions'],
            'time_range' => (string) $section['time_range'],
            'drill_through' => (string) $section['drill_through'],
        ];
    }
}

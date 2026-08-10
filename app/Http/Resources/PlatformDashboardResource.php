<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Http\Resources\Platform\PlatformDashboardAuditResource;
use App\Http\Resources\Platform\PlatformDashboardCommercialResource;
use App\Http\Resources\Platform\PlatformDashboardIntegrationsResource;
use App\Http\Resources\Platform\PlatformDashboardLifecycleResource;
use App\Http\Resources\Platform\PlatformDashboardRegistrationsResource;
use App\Http\Resources\Platform\PlatformDashboardTasksResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Super Administrator governance dashboard payload (Phase UI-08, contract page §5.4.1).
 *
 * Every section carries its own `availability`, `gate`, `definitions`, `time_range` and
 * `drill_through`, because a bare number on a governance dashboard is not evidence of anything —
 * the reader has to be able to see what it counts, over what window, and where to go to act on it.
 *
 * A Gate-W section reports `availability: disabled_by_gate` with null values, never `0`. On this
 * screen a fabricated zero is indistinguishable from a real one and reads as good news about a
 * system nobody can currently reach.
 *
 * ## Why the sections are nested RESOURCES
 *
 * Three encodings were tried against the OpenAPI generator and only one produces a usable
 * contract:
 *
 *   `array<string,mixed>`              every section published as `string`
 *   nested `array{...}` / import-type  every section still `string`; the alias published `{}`
 *   nested Resource                    a proper `$ref` per section
 *
 * The generator resolves a nested Resource but cannot express a nested array shape — the same
 * mechanism behind UI08-API-001, one level deeper. Six small flat resources are therefore what
 * makes the published schema and the generated TypeScript correct, and they are not ceremony.
 *
 * @property-read array<string,mixed> $resource
 */
final class PlatformDashboardResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $summary = $this->resource;

        return [
            'as_of' => (string) $summary['as_of'],
            'merchant_lifecycle' => new PlatformDashboardLifecycleResource($summary['merchant_lifecycle']),
            'commercial' => new PlatformDashboardCommercialResource($summary['commercial']),
            'registration_monitoring' => new PlatformDashboardRegistrationsResource($summary['registration_monitoring']),
            'governance_tasks' => new PlatformDashboardTasksResource($summary['governance_tasks']),
            'audit_alerts' => new PlatformDashboardAuditResource($summary['audit_alerts']),
            'integrations' => new PlatformDashboardIntegrationsResource($summary['integrations']),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function with(Request $request): array
    {
        return [
            'meta' => [
                'authorization_authority' => 'platform.merchant.view via MerchantPolicy::viewGovernance',
                'read_only' => true,
                'gate_policy' => 'A section blocked by an external gate reports availability=disabled_by_gate with null values. It is never reported as zero and never as healthy.',
            ],
        ];
    }
}

<?php

declare(strict_types=1);

namespace App\Http\Resources\Platform;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Integrations section of the Super Administrator dashboard (Phase UI-08 §5.4.1).
 *
 * Truthfully unavailable. Wallet client health, circuit-breaker state, webhook lag, reconciliation
 * exceptions, allocation drift and Refer & Earn qualification runs all require External Gate W.
 *
 * The three figures are typed `null` in the contract, not `int`. That is deliberate and is the
 * whole point of this resource: a `0` on a governance dashboard is indistinguishable from a real
 * zero and would tell the platform owner that a system they cannot even reach is working. The type
 * itself makes a fabricated zero impossible to publish.
 *
 * @property-read array<string,mixed> $resource
 */
final class PlatformDashboardIntegrationsResource extends JsonResource
{
    /**
     * @return array{
     *     availability:string, gate:string, gate_statement:string, wallet:null,
     *     reconciliation_exceptions:null, refer_and_earn:null,
     *     definitions:array<string,string>, time_range:null, drill_through:null
     * }
     */
    public function toArray(Request $request): array
    {
        $section = $this->resource;

        return [
            'availability' => (string) $section['availability'],
            'gate' => (string) $section['gate'],
            'gate_statement' => (string) $section['gate_statement'],
            'wallet' => null,
            'reconciliation_exceptions' => null,
            'refer_and_earn' => null,
            'definitions' => (array) $section['definitions'],
            'time_range' => null,
            'drill_through' => null,
        ];
    }
}

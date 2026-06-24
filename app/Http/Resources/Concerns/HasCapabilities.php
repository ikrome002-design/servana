<?php

declare(strict_types=1);

namespace App\Http\Resources\Concerns;

use Illuminate\Http\Request;

/**
 * Server-derived capability (`can`) maps for API resources (Plan §12, §23;
 * Correction 16.5).
 *
 * The backend is the single source of truth for what the current principal may do
 * with a resource: each capability is resolved through the model's Policy (which
 * reads the §10.3 permission registry). The frontend consumes this map for
 * visibility only — it is never a substitute for the server-side authorization
 * that still guards every mutating route.
 *
 * Only real, currently-implemented actions are listed; no future or speculative
 * abilities are exposed merely to populate the map.
 */
trait HasCapabilities
{
    /**
     * Resolve a capability map from policy abilities.
     *
     * @param  array<string, string>  $abilities  output key => policy ability name
     * @return array<string, bool>
     */
    protected function capabilities(Request $request, array $abilities): array
    {
        $user = $request->user();
        $map = [];

        foreach ($abilities as $key => $ability) {
            $map[$key] = $user !== null && $user->can($ability, $this->resource);
        }

        return $map;
    }
}

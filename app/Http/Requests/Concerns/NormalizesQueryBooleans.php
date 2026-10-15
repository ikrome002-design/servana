<?php

declare(strict_types=1);

namespace App\Http\Requests\Concerns;

/**
 * Query-string boolean filters (LOCAL-UAT-001, defect LUAT-D02).
 *
 * The OpenAPI contract declares list filters such as `active` as `in: query, schema: {type: boolean}`,
 * whose standard serialisation — and exactly what the SPA's HTTP client sends — is `true` / `false`.
 * Laravel's `boolean` rule accepts only true/false/1/0/"1"/"0", so `?active=true` was refused with a 422
 * and the screens built on these lists showed no data in a real browser.
 *
 * This maps ONLY the two literal words (any letter case) to booleans before validation. Every other
 * value still reaches the `boolean` rule unchanged and is still refused.
 */
trait NormalizesQueryBooleans
{
    /** @param list<string> $keys */
    protected function normalizeQueryBooleans(array $keys): void
    {
        $normalized = [];

        foreach ($keys as $key) {
            $value = $this->query($key);

            if (is_string($value) && in_array(strtolower($value), ['true', 'false'], true)) {
                $normalized[$key] = strtolower($value) === 'true';
            }
        }

        if ($normalized !== []) {
            $this->merge($normalized);
        }
    }
}

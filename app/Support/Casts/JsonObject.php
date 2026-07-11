<?php

declare(strict_types=1);

namespace App\Support\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * Casts a jsonb column that must always be a JSON **object** (never a bare array). An empty
 * value serializes to `{}` (not `[]`), matching the `jsonb_typeof(col) = 'object'` DB CHECK on
 * Phase-20A config columns (`subscription_plans.metadata`, `platform_billing_settings.settings`).
 * Reads return an associative array.
 *
 * @implements CastsAttributes<array<string, mixed>, array<string, mixed>>
 */
final class JsonObject implements CastsAttributes
{
    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): array
    {
        if ($value === null || $value === '') {
            return [];
        }

        /** @var array<string, mixed> $decoded */
        $decoded = (array) json_decode((string) $value, true);

        return $decoded;
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, string>
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): array
    {
        $array = $value === null ? [] : (array) $value;

        return [$key => $array === [] ? '{}' : (string) json_encode($array)];
    }
}

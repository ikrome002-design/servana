<?php

declare(strict_types=1);

namespace App\Domain\Tenancy\Services;

use App\Domain\Audit\Contracts\AuditRecorder;
use App\Domain\Audit\Enums\AuditSeverity;
use App\Domain\Tenancy\TenantContext;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Request;

/**
 * Records a cross-tenant access attempt (Plan §8.2, §8.4, §22.2).
 *
 * Called by scoped route binding when a public ULID is NOT found in the caller's
 * tenant scope: it performs a single UNSCOPED existence check (no row hydrated,
 * no field returned) to distinguish "genuinely missing" from "exists in another
 * tenant". Only the latter is a security event and is audited `high`. The
 * response is always a 404 — existence is never leaked, and no request body or
 * secret is recorded (only the model type, attempted ULID, route, and actor).
 */
final class LogUnauthorizedAttempt
{
    public function __construct(
        private readonly AuditRecorder $audit,
        private readonly TenantContext $context,
    ) {}

    /**
     * @param  class-string<Model>  $modelClass
     */
    public function record(string $modelClass, string $field, string $value): void
    {
        $instance = new $modelClass;

        // Unscoped boolean existence only — never hydrate or return the row.
        $existsElsewhere = $instance->newQueryWithoutScopes()
            ->where($field, $value)
            ->exists();

        if (! $existsElsewhere) {
            return; // genuinely not found anywhere — an ordinary 404, not an attack
        }

        $actor = Auth::user();
        $route = Request::route();

        $this->audit->record(
            'unauthorized_access',
            AuditSeverity::High,
            $actor instanceof User ? $actor : null,
            $this->context->merchantId(),
            null, // no subject — do not link/leak the foreign row
            [
                'model' => class_basename($modelClass),
                'field' => $field,
                'attempted_id' => $value,
                'route' => $route->getName(),
                'method' => Request::method(),
                'path' => Request::path(),
            ],
        );
    }
}

<?php

declare(strict_types=1);

namespace App\Domain\Tenancy\Exceptions;

use RuntimeException;

/**
 * Thrown when tenant-owned data is created or a tenant-aware job runs without a
 * resolved merchant context (Plan §8.2, §8.3).
 *
 * This is a programmer/security fault, not a client error — it means code tried
 * to persist a tenant row or touch tenant data outside any TenantContext and
 * without an explicit merchant_id. It is deliberately a 500-class RuntimeException
 * (not the 4xx envelope): a missing tenant scope must fail loudly, never degrade
 * to an unscoped write.
 */
final class MissingTenantContext extends RuntimeException
{
    public static function forModel(string $model): self
    {
        return new self(sprintf(
            'Cannot persist [%s]: no merchant_id was set and no TenantContext merchant is resolved.',
            $model,
        ));
    }

    public static function forJob(string $job): self
    {
        return new self(sprintf(
            'Cannot run tenant-aware job [%s]: no merchant id was captured at dispatch.',
            $job,
        ));
    }
}

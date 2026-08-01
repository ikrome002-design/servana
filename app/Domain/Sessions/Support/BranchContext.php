<?php

declare(strict_types=1);

namespace App\Domain\Sessions\Support;

/**
 * The minimal branch identity an account context needs (Phase UI-03).
 *
 * Three columns, nothing more. `AccountContextResolver` runs before any tenant context exists, so
 * it deliberately does NOT load a tenant-scoped `MerchantBranch` model — this tiny value object is
 * what it reads instead, which also means a branch's other columns can never be accidentally
 * serialized into a context payload.
 */
final readonly class BranchContext
{
    public function __construct(
        public int $id,
        public string $ulid,
        public string $name,
    ) {}
}

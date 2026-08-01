<?php

declare(strict_types=1);

namespace App\Domain\Sessions\Support;

use App\Domain\Sessions\Models\SessionFamily;
use App\Models\User;

/**
 * The outcome of a successful context-handoff consumption (ADR-018 step 10).
 *
 * `$context` was rebuilt from current database state during the consume transaction — it is NOT
 * the context the token was minted with. That distinction is the whole security property: what the
 * target session gets is what the database says now, not what the source session believed then.
 *
 * There is deliberately no failure variant: every rejection is a uniform `null`, so no caller can
 * branch on — or leak — which binding failed.
 */
final readonly class HandoffConsumeResult
{
    public function __construct(
        public User $user,
        public AccountContext $context,
        public ?SessionFamily $sourceFamily = null,
        public ?string $redirectPath = null,
    ) {}
}

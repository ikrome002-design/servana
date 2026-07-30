<?php

declare(strict_types=1);

namespace App\Domain\Auth\Support;

use App\Domain\Auth\Actions\ConsumeMagicLink;
use App\Domain\Sessions\Support\AccountContext;
use App\Models\User;

/**
 * The outcome of a successful bound Magic Link consumption (ADR-019; UI/UX plan §5.1).
 *
 * There is deliberately no failure variant: every failure is a uniform `null` from
 * {@see ConsumeMagicLink}, so no caller can accidentally branch on — or
 * leak — which binding was wrong.
 */
final readonly class MagicLinkConsumeResult
{
    public function __construct(
        public User $user,
        public AccountContext $context,
        public ?string $redirectPath = null,
    ) {}
}

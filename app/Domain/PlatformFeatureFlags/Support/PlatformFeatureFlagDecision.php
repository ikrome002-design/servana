<?php

declare(strict_types=1);

namespace App\Domain\PlatformFeatureFlags\Support;

/**
 * The outcome of a flag evaluation (COR-UI08-001 §12.4; Phase UI-08).
 *
 * The decision carries its REASON, not just a boolean, because "why is this off?" is the question an
 * operator actually has when a rollout is not behaving — and a bare `false` makes that unanswerable
 * without a debugger. The reason is a stable, non-sensitive code, never a message about a user.
 */
final readonly class PlatformFeatureFlagDecision
{
    private function __construct(
        public bool $allowed,
        public string $reason,
    ) {}

    public static function allow(string $reason): self
    {
        return new self(true, $reason);
    }

    public static function deny(string $reason): self
    {
        return new self(false, $reason);
    }

    /** @return array{allowed:bool,reason:string} */
    public function toArray(): array
    {
        return ['allowed' => $this->allowed, 'reason' => $this->reason];
    }
}

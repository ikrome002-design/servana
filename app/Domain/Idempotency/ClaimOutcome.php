<?php

declare(strict_types=1);

namespace App\Domain\Idempotency;

use App\Domain\Idempotency\Models\IdempotencyKey;

/**
 * The result of {@see IdempotencyStore::claim()} (Phase R4).
 */
final readonly class ClaimOutcome
{
    public function __construct(
        public ClaimResult $result,
        public ?IdempotencyKey $row = null,
        public ?int $retryAfterSeconds = null,
    ) {}

    public static function claimed(IdempotencyKey $row): self
    {
        return new self(ClaimResult::Claimed, $row);
    }

    public static function replay(IdempotencyKey $row): self
    {
        return new self(ClaimResult::Replay, $row);
    }

    public static function conflict(): self
    {
        return new self(ClaimResult::ConflictDifferent);
    }

    public static function inProgress(int $retryAfterSeconds): self
    {
        return new self(ClaimResult::InProgress, retryAfterSeconds: $retryAfterSeconds);
    }
}

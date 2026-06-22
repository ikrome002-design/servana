<?php

declare(strict_types=1);

namespace App\Domain\Auth\Support;

use App\Domain\Auth\Services\AccessRevocationService;

/**
 * Aggregate, secret-free result of an access-revocation pass (Plan §79 R6).
 *
 * Carries ONLY counts and a coarse category — never a session id, token hash,
 * Magic-Link value or invitation token (guardrail §6.4). Returned by
 * {@see AccessRevocationService} for tests, proof, and
 * audit context (safe aggregate counts only).
 */
final class RevocationSummary
{
    public function __construct(
        public readonly string $category,
        public readonly int $sessionsRevoked = 0,
        public readonly int $tokensRevoked = 0,
        public readonly int $magicLinksInvalidated = 0,
        public readonly int $invitationsRevoked = 0,
        public readonly int $usersAffected = 0,
    ) {}

    /**
     * Merge another summary into this one (same category), summing counts. Used
     * when a merchant-wide revocation aggregates per-user passes.
     */
    public function plus(self $other): self
    {
        return new self(
            $this->category,
            $this->sessionsRevoked + $other->sessionsRevoked,
            $this->tokensRevoked + $other->tokensRevoked,
            $this->magicLinksInvalidated + $other->magicLinksInvalidated,
            $this->invitationsRevoked + $other->invitationsRevoked,
            $this->usersAffected + $other->usersAffected,
        );
    }

    /**
     * Non-secret audit context (Plan §70). Safe aggregate counts only.
     *
     * @return array<string, int|string>
     */
    public function toAuditContext(): array
    {
        return [
            'revocation_category' => $this->category,
            'sessions_revoked' => $this->sessionsRevoked,
            'tokens_revoked' => $this->tokensRevoked,
            'magic_links_invalidated' => $this->magicLinksInvalidated,
            'invitations_revoked' => $this->invitationsRevoked,
        ];
    }
}

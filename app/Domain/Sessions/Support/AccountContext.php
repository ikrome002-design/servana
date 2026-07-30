<?php

declare(strict_types=1);

namespace App\Domain\Sessions\Support;

/**
 * One account context a user may currently enter (Phase UI-03; ADR-018 step 2; UI/UX plan §5.3).
 *
 * SERVER-DERIVED, ALWAYS. A context is built by reading `users`, `merchants`, `merchant_users` and
 * `branch_user_assignments` right now. It is never accepted from, reconstructed by, or corrected
 * against the browser — the browser only ever echoes back {@see $contextId}.
 *
 * CARRIES NO PERMISSIONS. It names WHICH context, never WHAT it may do. The target host resolves
 * authority from the database after the switch (ADR-018 step 7), which is exactly what stops a
 * broader source permission set leaking into a narrower target.
 *
 * The `$contextId` is an opaque HMAC (see {@see AccountContextIdentifier}): unguessable, stable for
 * as long as the underlying membership is, and meaningless to anyone who cannot recompute it. It
 * is validated by membership of the freshly derived list, never by parsing.
 */
final readonly class AccountContext
{
    /**
     * @param  int|null  $merchantId  internal id — used server-side only, never serialized
     * @param  int|null  $branchId  internal id — used server-side only, never serialized
     * @param  int|null  $merchantUserId  internal id — used server-side only, never serialized
     */
    public function __construct(
        public string $contextId,
        public string $accountKey,
        public string $displayName,
        public string $targetHost,
        public string $defaultRoute,
        public bool $requiresMfa,
        public ?string $merchantUlid = null,
        public ?string $merchantName = null,
        public ?string $branchUlid = null,
        public ?string $branchName = null,
        public ?string $roleLabel = null,
        public ?int $merchantId = null,
        public ?int $branchId = null,
        public ?int $merchantUserId = null,
    ) {}

    /**
     * The safe wire shape for the SPA.
     *
     * Deliberately excludes every internal id and every permission-shaped field. `is_current` is
     * supplied by the caller, which knows the requesting session's own context.
     *
     * @return array<string, mixed>
     */
    public function toArray(bool $isCurrent = false): array
    {
        return [
            'context_id' => $this->contextId,
            'account_key' => $this->accountKey,
            'display_name' => $this->displayName,
            'target_host' => $this->targetHost,
            'default_route' => $this->defaultRoute,
            'requires_mfa' => $this->requiresMfa,
            'merchant_id' => $this->merchantUlid,
            'merchant_name' => $this->merchantName,
            'branch_id' => $this->branchUlid,
            'branch_name' => $this->branchName,
            'role_label' => $this->roleLabel,
            'is_current' => $isCurrent,
        ];
    }
}

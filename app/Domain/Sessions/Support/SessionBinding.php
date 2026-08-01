<?php

declare(strict_types=1);

namespace App\Domain\Sessions\Support;

use App\Domain\Merchants\Models\MerchantUser;

/**
 * The outcome of checking which account a request's session is bound to (Phase UI-03).
 *
 * This exists so the TENANT layer never has to know how a host is resolved. The session/host
 * boundary does that work and hands back one of three neutral answers; `TenantContextResolver`
 * consumes the answer and nothing else. That direction is enforced by the structural guard in
 * `AccountHostDoesNotAuthorizeTest`, which forbids any account-host reference inside the
 * authorization layers — and it is the right direction regardless of the guard: the tenant layer
 * decides authority, and authority must not be a function of the `Host` header (ADR-017).
 *
 * The three answers are deliberately distinct. Collapsing "absent" and "mismatch" into a single
 * null was the first shape of this fix and it was wrong: they must behave differently, because one
 * legitimately falls back and the other must fail closed.
 *
 * It carries NO permissions, no grants, no host header value and nothing the browser supplied. The
 * membership is an identifier for the intended context; every authority is resolved fresh from it.
 */
final readonly class SessionBinding
{
    private function __construct(
        public bool $present,
        public bool $agrees,
        public ?MerchantUser $membership,
    ) {}

    /**
     * This request is not a bound browser account request at all.
     *
     * Machine traffic, token callers, queue and scheduler work, public routes and the existing
     * non-stateful test contract land here. The tenant layer keeps its previous behaviour, which is
     * unambiguous for these callers because they resolve a user with exactly one active membership.
     */
    public static function absent(): self
    {
        return new self(present: false, agrees: true, membership: null);
    }

    /**
     * A binding was required but is missing, revoked, foreign, stale, or addressed to another host.
     *
     * The tenant context must stay EMPTY. Falling back to another membership the user happens to
     * hold would hand a request addressed to one account the authority of a different account.
     */
    public static function mismatch(): self
    {
        return new self(present: true, agrees: false, membership: null);
    }

    /** An agreeing binding to a PLATFORM session, which owns no merchant context. */
    public static function platform(): self
    {
        return new self(present: true, agrees: true, membership: null);
    }

    /** An agreeing binding to a merchant account, naming the membership it operates as. */
    public static function merchant(MerchantUser $membership): self
    {
        return new self(present: true, agrees: true, membership: $membership);
    }

    /** True when the tenant layer must resolve nothing and let downstream gates deny. */
    public function failsClosed(): bool
    {
        return $this->present && ! $this->agrees;
    }

    /** True when the tenant layer should apply its ordinary, unbound resolution. */
    public function fallsBack(): bool
    {
        return ! $this->present;
    }
}

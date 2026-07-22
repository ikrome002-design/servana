<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\Compensation\Models\EarningsQuery;
use App\Domain\Tenancy\TenantContext;
use App\Models\User;

/**
 * Phase 20H earnings-query authority (Plan §63, §19.3; D-H12-1). Defence-in-depth alongside the route
 * `EnsurePermission` middleware. Personnel raise + read their OWN queries
 * (`personnel.my_earnings_query.create`); the own-scope restriction (subject/query belongs to the
 * acting staff profile) is enforced in the controller/action, never by a client-supplied id. Finance
 * is the sole authoritative responder (`earnings_query.respond`) — a monetary correction is an additive
 * compensation adjustment, never a silent ledger edit.
 */
final class EarningsQueryPolicy
{
    public function __construct(private readonly TenantContext $context) {}

    /** Personnel: raise a query against an own compensation fact. */
    public function create(User $user): bool
    {
        return $this->context->can('personnel.my_earnings_query.create');
    }

    /** Personnel: view an own query (own-scope enforced in the controller). */
    public function viewOwn(User $user, EarningsQuery $query): bool
    {
        return $this->context->can('personnel.my_earnings_query.create');
    }

    /** Finance: view the responder work queue / a query for triage. */
    public function viewAsResponder(User $user): bool
    {
        return $this->context->can('earnings_query.respond');
    }

    /** Finance: resolve/reject a query (a correction flows through a compensation adjustment). */
    public function respond(User $user, EarningsQuery $query): bool
    {
        return $this->context->can('earnings_query.respond');
    }
}

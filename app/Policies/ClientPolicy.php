<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\Clients\Models\Client;
use App\Domain\Tenancy\TenantContext;
use App\Models\User;

/**
 * Client-record authority (Plan §10.2/§19.3, §35). Front Office owns client
 * records in its own branch scope: `client.view` (read, masked), `client.create`,
 * `client.update` (incl. SMS-consent change). Contact is always masked at read and
 * never exported. Cross-merchant rows are 404'd upstream; same-merchant + branch
 * scope is re-checked here.
 */
final class ClientPolicy
{
    public function __construct(private readonly TenantContext $context) {}

    public function viewAny(User $user): bool
    {
        return $this->context->can('client.view');
    }

    public function view(User $user, Client $client): bool
    {
        return $this->context->can('client.view') && $this->ownsBranch($client);
    }

    public function create(User $user): bool
    {
        return $this->context->can('client.create');
    }

    public function update(User $user, Client $client): bool
    {
        return $this->context->can('client.update') && $this->ownsBranch($client);
    }

    /** SMS-consent change is part of client management (`client.update`). */
    public function manageConsent(User $user, Client $client): bool
    {
        return $this->update($user, $client);
    }

    private function ownsBranch(Client $client): bool
    {
        return $client->merchant_id === $this->context->merchantId()
            && $this->context->canAccessBranch($client->branch_id);
    }
}

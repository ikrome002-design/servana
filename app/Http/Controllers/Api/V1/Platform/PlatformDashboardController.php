<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Platform;

use App\Domain\Merchants\Models\Merchant;
use App\Domain\Platform\Queries\PlatformDashboardProjection;
use App\Http\Controllers\Controller;
use App\Http\Resources\PlatformDashboardResource;

/**
 * Super Administrator governance dashboard (Phase UI-08, contract page §5.4.1).
 *
 * The single new operation UI-08 adds. It is a READ: one aggregate projection over data that
 * already exists, behind the permission the shipped merchant-governance reads already require
 * (`platform.merchant.view`, expressed by `MerchantPolicy::viewGovernance`). No new permission
 * key, no table, no migration, no state machine, no financial calculation.
 *
 * Reusing `viewGovernance` is deliberate: a second policy for the same authority would be a
 * duplicate statement of one rule, and the two would eventually disagree.
 */
final class PlatformDashboardController extends Controller
{
    public function __construct(private readonly PlatformDashboardProjection $projection) {}

    public function show(): PlatformDashboardResource
    {
        $this->authorize('viewGovernance', Merchant::class);

        return PlatformDashboardResource::make($this->projection->summary());
    }
}

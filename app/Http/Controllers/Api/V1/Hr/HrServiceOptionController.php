<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Hr;

use App\Domain\Catalogue\Models\Service;
use App\Domain\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use App\Http\Requests\Hr\HrServiceOptionIndexRequest;
use App\Http\Resources\CompensationSelectableServiceResource;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/** Minimal active-service option read for HR eligibility; no catalogue terms or controls. */
final class HrServiceOptionController extends Controller
{
    public function __construct(private readonly TenantContext $context) {}

    public function index(HrServiceOptionIndexRequest $request): AnonymousResourceCollection
    {
        return CompensationSelectableServiceResource::collection(
            Service::query()
                ->active()
                ->whereIn('branch_id', $this->context->branchIds())
                ->orderBy('name')
                ->orderBy('ulid')
                ->get(),
        );
    }
}

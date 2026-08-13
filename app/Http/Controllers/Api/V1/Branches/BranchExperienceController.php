<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Branches;

use App\Domain\Branches\Models\MerchantBranch;
use App\Domain\Branches\Services\BranchWorkspaceReadModel;
use App\Http\Controllers\Controller;
use App\Http\Requests\Branches\BranchDashboardRequest;
use Illuminate\Http\JsonResponse;

/** Assigned-branch overview; authorization is enforced by route middleware. */
final class BranchExperienceController extends Controller
{
    public function show(
        BranchDashboardRequest $request,
        MerchantBranch $branch,
        BranchWorkspaceReadModel $readModel,
    ): JsonResponse {
        return response()->json(['data' => ['overview' => $readModel->forBranch($branch->load('merchant'))]]);
    }
}

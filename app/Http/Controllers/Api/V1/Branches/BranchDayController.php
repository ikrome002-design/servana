<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Branches;

use App\Domain\Branches\Actions\CloseBranchDay;
use App\Domain\Branches\Actions\OpenBranchDay;
use App\Domain\Branches\Models\MerchantBranch;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Branch day open/close (Scope §3.3 Day Opening/Closing). Branch-scoped
 * (EnsureBranchScope). Full day-close reporting/cash-up is Phase 18.
 */
final class BranchDayController extends Controller
{
    public function open(Request $request, MerchantBranch $branch, OpenBranchDay $action): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();
        $record = $action->handle($branch, $actor);

        return response()->json([
            'data' => ['business_date' => $record->business_date->toDateString(), 'status' => $record->status->value],
        ]);
    }

    public function close(Request $request, MerchantBranch $branch, CloseBranchDay $action): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();
        $record = $action->handle($branch, $actor);

        return response()->json([
            'data' => ['business_date' => $record->business_date->toDateString(), 'status' => $record->status->value],
        ]);
    }
}

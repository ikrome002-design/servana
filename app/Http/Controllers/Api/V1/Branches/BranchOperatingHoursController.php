<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Branches;

use App\Domain\Branches\Models\BranchOperatingHour;
use App\Domain\Branches\Models\MerchantBranch;
use App\Http\Controllers\Controller;
use App\Http\Requests\Branches\UpdateOperatingHoursRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/**
 * Branch weekly operating hours (Scope §3.3). Branch-scoped (EnsureBranchScope);
 * the configuring role is Branch Manager (coarse — Phase 8 adds the real grant).
 */
final class BranchOperatingHoursController extends Controller
{
    public function show(MerchantBranch $branch): JsonResponse
    {
        $hours = $branch->operatingHours()->orderBy('weekday')->get()
            ->map(static fn (BranchOperatingHour $h): array => [
                'weekday' => $h->weekday,
                'opens_at' => $h->opens_at,
                'closes_at' => $h->closes_at,
                'is_closed' => $h->is_closed,
                'break_start' => $h->break_start,
                'break_end' => $h->break_end,
            ])->values();

        return response()->json(['data' => $hours]);
    }

    public function update(UpdateOperatingHoursRequest $request, MerchantBranch $branch): JsonResponse
    {
        /** @var array<int, array<string, mixed>> $hours */
        $hours = $request->validated()['hours'];

        DB::transaction(function () use ($branch, $hours): void {
            foreach ($hours as $entry) {
                BranchOperatingHour::query()->updateOrCreate(
                    ['branch_id' => $branch->id, 'weekday' => (int) $entry['weekday']],
                    [
                        'opens_at' => $entry['opens_at'] ?? null,
                        'closes_at' => $entry['closes_at'] ?? null,
                        'is_closed' => (bool) $entry['is_closed'],
                        'break_start' => $entry['break_start'] ?? null,
                        'break_end' => $entry['break_end'] ?? null,
                    ],
                );
            }
        });

        return $this->show($branch);
    }
}

<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Branches;

use App\Domain\Audit\Contracts\AuditRecorder;
use App\Domain\Audit\Enums\AuditEvent;
use App\Domain\Branches\Models\BranchOperatingHour;
use App\Domain\Branches\Models\MerchantBranch;
use App\Http\Controllers\Controller;
use App\Http\Requests\Branches\UpdateOperatingHoursRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/**
 * Branch weekly operating hours (Scope §3.3). Branch-scoped (EnsureBranchScope);
 * the configuring role is Branch Manager (`branch.profile.manage`). There is no
 * domain action for the weekly upsert, so the change is audited here inside the
 * same transaction as the upsert (Plan §70).
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

    public function update(UpdateOperatingHoursRequest $request, MerchantBranch $branch, AuditRecorder $audit): JsonResponse
    {
        /** @var array<int, array<string, mixed>> $hours */
        $hours = $request->validated()['hours'];

        DB::transaction(function () use ($branch, $hours, $audit, $request): void {
            foreach ($hours as $entry) {
                BranchOperatingHour::query()->updateOrCreate(
                    ['branch_id' => $branch->id, 'weekday' => (int) $entry['weekday']],
                    [
                        // merchant_id derives from the branch (R5 consistency FK).
                        'merchant_id' => $branch->merchant_id,
                        'opens_at' => $entry['opens_at'] ?? null,
                        'closes_at' => $entry['closes_at'] ?? null,
                        'is_closed' => (bool) $entry['is_closed'],
                        'break_start' => $entry['break_start'] ?? null,
                        'break_end' => $entry['break_end'] ?? null,
                    ],
                );
            }

            $audit->record(
                AuditEvent::BranchOperatingHoursUpdated,
                $request->user(),
                $branch->merchant_id,
                $branch->id,
                $branch,
                ['weekdays' => array_map(static fn (array $e): int => (int) $e['weekday'], $hours)],
            );
        });

        return $this->show($branch);
    }
}

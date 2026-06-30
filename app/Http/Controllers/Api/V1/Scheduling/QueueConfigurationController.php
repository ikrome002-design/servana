<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Scheduling;

use App\Domain\Scheduling\Actions\UpdateQueueConfiguration;
use App\Domain\Scheduling\Enums\QueueAssignmentMode;
use App\Domain\Scheduling\Services\QueueCapacityGuard;
use App\Domain\Tenancy\TenantContext;
use App\Http\Controllers\Concerns\ResolvesWriteBranch;
use App\Http\Controllers\Controller;
use App\Http\Requests\Scheduling\UpdateQueueConfigurationRequest;
use App\Http\Resources\QueueConfigurationResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;

/**
 * Branch queue configuration (Plan §37; Phase 16B). Branch Manager configures the
 * queue (open/close, capacity, default assignment mode) on today's Branch Day via
 * `branch.profile.manage` + `day.open_close` — this is NOT an operational entry
 * mutation. Front Office + Branch Manager may read it. There is deliberately no
 * `queue_configurations` table.
 */
final class QueueConfigurationController extends Controller
{
    use ResolvesWriteBranch;

    public function __construct(
        private readonly TenantContext $context,
        private readonly QueueCapacityGuard $capacity,
    ) {}

    public function show(UpdateQueueConfigurationRequest $request): JsonResponse
    {
        abort_unless(
            $this->context->can('branch.dashboard.view')
            || $this->context->can('queue.view')
            || $this->context->can('branch.profile.manage'),
            403,
        );

        $branch = $this->resolveWriteBranch($this->context, $request->validated('branch_id'));
        $day = $this->capacity->todayBranchDay($branch);

        if ($day === null) {
            return response()->json(['data' => [
                'branch_day_id' => null,
                'business_date' => now('Africa/Nairobi')->toDateString(),
                'day_status' => 'not_opened',
                'queue_is_open' => false,
                'effective_queue_open' => false,
                'queue_capacity' => null,
                'queue_default_assignment_mode' => QueueAssignmentMode::NextAvailable->value,
                'active_count' => 0,
            ]]);
        }

        return QueueConfigurationResource::make($day, $this->capacity->activeCount($branch))->response();
    }

    public function update(UpdateQueueConfigurationRequest $request, UpdateQueueConfiguration $action): JsonResponse
    {
        abort_unless(
            $this->context->can('branch.profile.manage') && $this->context->can('day.open_close'),
            403,
        );

        $data = $request->validated();
        $branch = $this->resolveWriteBranch($this->context, $data['branch_id'] ?? null);

        /** @var User $actor */
        $actor = $request->user();

        $day = $action->handle(
            $branch,
            $actor,
            array_key_exists('queue_is_open', $data) ? (bool) $data['queue_is_open'] : null,
            array_key_exists('queue_capacity', $data),
            array_key_exists('queue_capacity', $data) ? ($data['queue_capacity'] !== null ? (int) $data['queue_capacity'] : null) : null,
            isset($data['queue_default_assignment_mode']) ? QueueAssignmentMode::from((string) $data['queue_default_assignment_mode']) : null,
        );

        return QueueConfigurationResource::make($day, $this->capacity->activeCount($branch))->response();
    }
}

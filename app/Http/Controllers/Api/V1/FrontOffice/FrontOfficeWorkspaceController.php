<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\FrontOffice;

use App\Domain\Audit\Models\AuditLog;
use App\Domain\FrontOffice\Services\FrontOfficeWorkspaceReadModel;
use App\Domain\Payments\Models\PaymentRecordingGroup;
use App\Http\Api\ApiPagination;
use App\Http\Controllers\Controller;
use App\Http\Requests\FrontOffice\FrontOfficeActivityIndexRequest;
use App\Http\Requests\FrontOffice\FrontOfficePaymentStatusIndexRequest;
use App\Http\Requests\FrontOffice\FrontOfficeWorkspaceRequest;
use App\Http\Resources\FrontOfficeActivityResource;
use App\Http\Resources\FrontOfficePaymentStatusResource;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/** UI-13 read-only Front Office presentation endpoints. */
final class FrontOfficeWorkspaceController extends Controller
{
    public function show(
        FrontOfficeWorkspaceRequest $request,
        FrontOfficeWorkspaceReadModel $readModel,
    ): JsonResponse {
        return response()->json(['data' => ['overview' => $readModel->read()]]);
    }

    public function activity(
        FrontOfficeActivityIndexRequest $request,
        FrontOfficeWorkspaceReadModel $readModel,
    ): AnonymousResourceCollection {
        $filters = $request->validated();
        $branch = $readModel->branch();
        $today = now('Africa/Nairobi');
        $query = AuditLog::query()
            ->where('merchant_id', $branch->merchant_id)
            ->where('branch_id', $branch->id)
            ->whereBetween('created_at', [
                $today->copy()->startOfDay()->utc(),
                $today->copy()->endOfDay()->utc(),
            ])
            ->where(static function (Builder $events): void {
                $events
                    ->where('action', 'like', 'client.%')
                    ->orWhere('action', 'like', 'client_consent.%')
                    ->orWhere('action', 'like', 'appointment.%')
                    ->orWhere('action', 'like', 'walk_in.%')
                    ->orWhere('action', 'like', 'queue_entry.%')
                    ->orWhere('action', 'like', 'service_session.%')
                    ->orWhere('action', 'like', 'invoice.%')
                    ->orWhere('action', 'like', 'customer_payment.%')
                    ->orWhere('action', 'like', 'receipt.%');
            });

        if (isset($filters['domain'])) {
            $this->applyActivityDomain($query, $filters['domain']);
        }
        ApiPagination::applySort($query, $filters['sort'] ?? null, 'created_at');

        return FrontOfficeActivityResource::collection(
            $query->paginate(ApiPagination::perPage($filters))->withQueryString(),
        );
    }

    public function paymentStatus(
        FrontOfficePaymentStatusIndexRequest $request,
    ): AnonymousResourceCollection {
        $filters = $request->validated();
        $query = PaymentRecordingGroup::query()
            ->with(['invoice', 'validatedEvent.receipt']);

        if (isset($filters['status'])) {
            $query->where('status', $filters['status']);
        }
        ApiPagination::applySort($query, $filters['sort'] ?? null, 'recorded_at');

        return FrontOfficePaymentStatusResource::collection(
            $query->paginate(ApiPagination::perPage($filters))->withQueryString(),
        );
    }

    /** @param Builder<AuditLog> $query */
    private function applyActivityDomain(Builder $query, string $domain): void
    {
        $query->where(static function (Builder $events) use ($domain): void {
            match ($domain) {
                'clients' => $events->where('action', 'like', 'client.%')->orWhere('action', 'like', 'client_consent.%'),
                'appointments' => $events->where('action', 'like', 'appointment.%'),
                'queue' => $events->where('action', 'like', 'walk_in.%')->orWhere('action', 'like', 'queue_entry.%'),
                'sessions' => $events->where('action', 'like', 'service_session.%'),
                'invoices' => $events->where('action', 'like', 'invoice.%'),
                'billing' => $events->where('action', 'like', 'customer_payment.%')->orWhere('action', 'like', 'receipt.%'),
                default => null,
            };
        });
    }
}

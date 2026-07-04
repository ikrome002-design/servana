<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Payments;

use App\Domain\Payments\Actions\CorrectPaymentReference;
use App\Domain\Payments\Models\PaymentRecord;
use App\Http\Controllers\Controller;
use App\Http\Requests\Payments\CorrectPaymentReferenceRequest;
use App\Http\Resources\PaymentRecordResource;
use App\Models\User;

/**
 * Component-level payment operations (Plan §42; Phase 18B). Currently: reference
 * correction on a correctable group. `financial_mutation` (route-level idempotency);
 * the corrected reference is validated method-aware, re-checked for duplicates, and
 * audited MASKED (before/after) — the full/normalized reference is never echoed back.
 */
final class PaymentRecordController extends Controller
{
    /** Correct a component's payment reference on a correction_required group. */
    public function correctReference(CorrectPaymentReferenceRequest $request, PaymentRecord $paymentRecord, CorrectPaymentReference $action): PaymentRecordResource
    {
        $this->authorize('correctReference', $paymentRecord);

        /** @var User $actor */
        $actor = $request->user();

        return PaymentRecordResource::make($action->handle($paymentRecord, $actor, (string) $request->validated('reference')));
    }
}

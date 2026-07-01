<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Payments;

use App\Domain\Payments\Actions\ApproveDuplicatePaymentReference;
use App\Domain\Payments\Models\PaymentReferenceCheck;
use App\Http\Controllers\Controller;
use App\Http\Requests\Payments\ApproveDuplicateReferenceRequest;
use App\Http\Resources\PaymentReferenceCheckResource;
use App\Models\User;

/**
 * Finance duplicate-reference override (Plan §41, Gate C; Phase 18A). Route-gated by
 * `customer_payment.duplicate_override` + MFA + fresh step-up + idempotency
 * (financial_mutation). Delegates to the transactional action, which enforces
 * maker/checker separation, writes durable `override_approved` evidence without
 * editing the original reference, and — once every suspected duplicate is cleared —
 * advances the held group to `pending_validation`.
 */
final class PaymentReferenceCheckController extends Controller
{
    public function override(
        ApproveDuplicateReferenceRequest $request,
        PaymentReferenceCheck $paymentReferenceCheck,
        ApproveDuplicatePaymentReference $action,
    ): PaymentReferenceCheckResource {
        $this->authorize('override', $paymentReferenceCheck);

        /** @var User $actor */
        $actor = $request->user();

        $override = $action->handle($paymentReferenceCheck, $actor, (string) $request->validated('reason'));

        return PaymentReferenceCheckResource::make($override->load(['record', 'matchedRecord']));
    }
}

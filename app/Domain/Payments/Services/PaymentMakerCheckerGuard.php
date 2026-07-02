<?php

declare(strict_types=1);

namespace App\Domain\Payments\Services;

use App\Domain\Payments\Exceptions\PaymentRecordingException;
use App\Domain\Payments\Models\PaymentRecordingGroup;

/**
 * Maker/checker separation guard (Plan §10.2, §41, §42; Phase 18A). Preserves the
 * boundary Phase 18B validation will rely on: the recording maker
 * (`group.maker_user_id`) may never act as the checker/override actor for the SAME
 * group. The canonical permission incompatibility (customer_payment.record vs
 * customer_payment.validate) is enforced by the registry (different roles); this
 * guard enforces it at the row level for the duplicate override and the future
 * validation handoff.
 */
final class PaymentMakerCheckerGuard
{
    public function ensureNotMaker(PaymentRecordingGroup $group, int $checkerUserId): void
    {
        if ($group->maker_user_id === $checkerUserId) {
            throw PaymentRecordingException::makerIsChecker();
        }
    }
}

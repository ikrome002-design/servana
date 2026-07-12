<?php

declare(strict_types=1);

namespace App\Http\Requests\Billing;

/**
 * Validate a promotional-discount DRAFT edit (Plan §53; Gate C6; Phase 20C). A draft edit is a full
 * re-statement of the draft definition (same shape as creation) — approved records are immutable and
 * reject the update at the action layer. Targets are replaced wholesale. Reuses the creation rules.
 */
final class UpdatePromotionalDiscountRequest extends StorePromotionalDiscountRequest {}

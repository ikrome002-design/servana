<?php

declare(strict_types=1);

namespace App\Http\Requests\Billing;

/**
 * Validate a free-period-offer DRAFT edit (Plan §53; Gate C6; Phase 20C). A full re-statement of the
 * draft definition; approved records are immutable and reject the update at the action layer. Targets
 * are replaced wholesale. Reuses the creation rules.
 */
final class UpdateFreePeriodOfferRequest extends StoreFreePeriodOfferRequest {}

<?php

declare(strict_types=1);

namespace App\Http\Requests\Messaging;

/**
 * Validated input for an SMS preview (Plan §64; Phase 21S).
 *
 * Preview is ADVISORY: it creates nothing, sends nothing and bills nothing. It nevertheless takes
 * exactly the same validated contract as draft creation
 * ({@see PersonnelSmsCompositionRequest}), so what a Personnel user previews is what they can
 * subsequently create — no field is accepted here that would be rejected there, or vice versa.
 */
final class PreviewPersonnelSmsCampaignRequest extends PersonnelSmsCompositionRequest {}

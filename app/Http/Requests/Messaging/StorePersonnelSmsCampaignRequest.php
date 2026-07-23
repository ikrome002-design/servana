<?php

declare(strict_types=1);

namespace App\Http\Requests\Messaging;

use App\Domain\Messaging\Sms\Actions\ConfirmSmsCampaign;

/**
 * Validated input for creating an SMS campaign DRAFT (Plan §64; Phase 21S).
 *
 * Same contract as the preview ({@see PersonnelSmsCompositionRequest}) — the same two
 * client-supplied fields and the same server-owned prohibitions — because the draft must be
 * composed from exactly what was previewed and nothing more. Creating the draft persists the
 * recipient snapshots; it still bills nothing and sends nothing, which is
 * {@see ConfirmSmsCampaign}'s job.
 */
final class StorePersonnelSmsCampaignRequest extends PersonnelSmsCompositionRequest {}

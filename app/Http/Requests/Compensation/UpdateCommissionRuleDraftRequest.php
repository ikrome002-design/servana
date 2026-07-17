<?php

declare(strict_types=1);

namespace App\Http\Requests\Compensation;

/**
 * Update a DRAFT commission rule in place (Plan §59; Phase 20F, F7). The only in-place edit a rule
 * ever gets: the state machine rejects an active/scheduled/terminal edit and the DB BEFORE UPDATE
 * trigger is the authoritative guard. An active rule's terms change only by SUPERSEDE, and a
 * previously active rule is ENDED, never deleted (Scope §12.7 Step 3C).
 *
 * Identical validation to create — the value shape is re-checked on every edit.
 */
final class UpdateCommissionRuleDraftRequest extends StoreCommissionRuleRequest {}

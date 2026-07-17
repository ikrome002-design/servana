<?php

declare(strict_types=1);

namespace App\Http\Requests\Compensation;

/**
 * Update a DRAFT compensation plan in place (Plan §59; Phase 20F, F7). The ONLY in-place edit in
 * the aggregate — the state machine rejects a non-draft edit and the DB BEFORE UPDATE trigger is
 * the authoritative guard. Effective terms change by SUPERSEDE (a new version), never by editing.
 *
 * The subject (`staff_profile_id`) is fixed at creation and is NOT accepted here: re-pointing a
 * plan at another personnel would silently rewrite whose compensation it is. Everything else
 * re-validates exactly as at create (same F1 model shape), so a draft can never be saved into a
 * shape that would be illegal at approval.
 */
final class UpdateCompensationPlanDraftRequest extends StoreCompensationPlanRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        $rules = parent::rules();

        // The compensation SUBJECT is immutable — a different personnel is a different plan.
        unset($rules['staff_profile_id']);

        return $rules;
    }
}

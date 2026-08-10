<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Platform feature-flag catalogue (COR-UI08-001 §12; Phase UI-08)
|--------------------------------------------------------------------------
|
| THE CATALOGUE IS CODE, NOT DATA. The API can only ever act on a key that
| already exists here, so no operator can mint a flag at runtime and no flag
| can exist that was never code-reviewed. An unknown key fails closed.
|
| AN EMPTY PRODUCTION CATALOGUE IS A TRUTHFUL STATE. Servana ships with no
| platform feature flag defined, because none has been authorized. The page
| renders an honest empty state rather than being padded with fabricated
| entries to look populated — inventing a flag to fill a screen would be
| exactly the "production mock data" the UI/UX plan §15.2 forbids.
|
| Each definition records:
|
|   owner                 the team accountable for the rollout
|   description           what the flag actually gates
|   risk_class            low | medium | high
|   environments          the environments in which it may be switched at all
|   target_types          merchant | plan | cohort — the closed targeting set
|   dependencies          other flag keys that must be active first
|   affected_screen_keys  navigation-map screen keys the flag can change
|   affected_operation_ids OpenAPI operation ids the flag can change
|   health_metric_key     null when no metric exists — never a fabricated one
|   rollback_criterion    the observable condition that triggers rollback
|   external_gate         'W' when the capability additionally sits behind a
|                         closed external gate; the evaluator reads this as an
|                         extra DENY and NOTHING here can ever open a gate
|
*/

return [
    'flags' => [
        // Deliberately empty. See the note above: an empty catalogue is truthful,
        // and a fabricated flag would be production mock data.
    ],
];

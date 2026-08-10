<?php

declare(strict_types=1);

use Symfony\Component\Yaml\Yaml;

uses()->group('docs', 'ui08', 'contracts');

/*
 |==============================================================================
 | Phase UI-08 — COR-UI08-001 corrective backend contract (Increment 2b).
 |
 | Specification before implementation. ONE artifact describes the four corrective
 | backend domains:
 |
 |   docs/backend/audits/ui-08/cor-ui08-001-contract-matrix.json
 |
 | These cases prove the specification is internally consistent and consistent with every
 | authority it depends on BEFORE a controller or migration exists: the decision record, the
 | readiness matrix, the permission matrix, the data dictionary, the state machines, the
 | committed OpenAPI baseline and the route collection.
 |
 | THE MATRIX IS ALSO THE RUNTIME CONTRACT, not just a plan. Every operation carries an
 | `implementation_state`. While it is `planned`, this suite asserts the route does NOT yet
 | exist (a negative control that catches a half-landed domain). When an increment flips it to
 | `implemented`, the SAME suite starts asserting the live route, its exact middleware, its
 | permission, its step-up and its OpenAPI operation. Nothing is ever weakened to accommodate
 | missing implementation — the assertion changes direction, not strength.
 |
 | Everything here is deterministic and offline apart from the route collection, which Laravel
 | builds without a database.
 */

const UI08_MATRIX = 'docs/backend/audits/ui-08/cor-ui08-001-contract-matrix.json';
const UI08_READINESS = 'docs/frontend/audits/ui-08/page-readiness-matrix.json';
const UI08_DECISION = 'docs/decisions/cor-ui08-001-super-administrator-backend-enablement.md';
const UI08_PERMISSION_MATRIX = 'docs/auth/permission-matrix.yaml';
const UI08_OPENAPI = 'docs/api/openapi.json';

/** The four domains COR-UI08-001 authorizes — no more, no fewer. */
const UI08_DOMAINS = [
    'sms_billing_settings',
    'subscription_operations',
    'internal_platform_access',
    'platform_feature_flags',
];

/** The two permission keys Increment 2a added. Any third key is unauthorized. */
const UI08_NEW_PERMISSION_KEYS = [
    'platform.internal_access.view',
    'platform.internal_access.manage',
];

function ui08Path(string $relative): string
{
    return base_path($relative);
}

/** @return array<string,mixed> */
function ui08Matrix(): array
{
    static $matrix = null;

    if ($matrix === null) {
        $matrix = json_decode((string) file_get_contents(ui08Path(UI08_MATRIX)), true, 512, JSON_THROW_ON_ERROR);
    }

    return $matrix;
}

/**
 * Operations authorized ONE AT A TIME outside COR-UI08-001, from the route-activation matrix.
 *
 * A page-specific read that the four corrective domains did not specify is justified individually
 * there, with a written reason, rather than being folded into the 33 — so the two authorization
 * routes stay separately auditable.
 *
 * @return list<array<string,mixed>>
 */
function ui08ItemisedOperations(): array
{
    $matrix = json_decode(
        (string) file_get_contents(ui08Path('docs/frontend/audits/ui-08/route-activation-matrix.json')),
        true,
        512,
        JSON_THROW_ON_ERROR,
    );

    return $matrix['new_backend_operations'] ?? [];
}

/** Every operation across every domain, flattened, each tagged with its domain. */
function ui08Operations(): array
{
    $operations = [];

    foreach (ui08Matrix()['domains'] as $domain) {
        foreach ($domain['operations'] as $operation) {
            $operation['__domain'] = $domain['domain'];
            $operations[] = $operation;
        }
    }

    return $operations;
}

// ---------------------------------------------------------------------------------------------
// The artifact itself
// ---------------------------------------------------------------------------------------------

it('carries a valid, deterministic contract matrix', function (): void {
    $path = ui08Path(UI08_MATRIX);

    expect(file_exists($path))->toBeTrue(UI08_MATRIX.' must exist — it is the Increment 2b authority');

    $matrix = ui08Matrix();

    expect($matrix['schema'])->toBe('servana.backend-audit.cor-ui08-001-contract-matrix.v1');
    expect($matrix['decision_id'])->toBe('COR-UI08-001');
    expect($matrix['phase'])->toBe('UI-08');
    expect($matrix['account'])->toBe('super_administrator');

    // Deterministic: no wall-clock field may make the artifact stale on a rerun.
    $raw = (string) file_get_contents($path);
    foreach (['generated_at', 'measured_at', 'timestamp', 'run_at'] as $forbidden) {
        expect($raw)->not->toContain('"'.$forbidden.'"');
    }
});

it('references the decision record and the readiness matrix that authorize it', function (): void {
    expect(file_exists(ui08Path(UI08_DECISION)))->toBeTrue();
    expect(ui08Matrix()['decision_record'])->toBe(UI08_DECISION);

    $readiness = json_decode((string) file_get_contents(ui08Path(UI08_READINESS)), true, 512, JSON_THROW_ON_ERROR);

    // The four corrective pages in the readiness matrix are exactly the four domains here.
    $correctiveRoutes = [];
    foreach ($readiness['pages'] as $page) {
        if (($page['decision_id'] ?? null) === 'COR-UI08-001') {
            $correctiveRoutes[] = $page['canonical_route'];
        }
    }
    sort($correctiveRoutes);

    $matrixRoutes = array_map(
        static fn (array $domain): string => $domain['canonical_route'],
        ui08Matrix()['domains'],
    );
    sort($matrixRoutes);

    expect($matrixRoutes)->toBe($correctiveRoutes)
        ->and($matrixRoutes)->toHaveCount(4);
});

it('describes exactly the four authorized domains', function (): void {
    $domains = array_map(
        static fn (array $domain): string => $domain['domain'],
        ui08Matrix()['domains'],
    );

    expect($domains)->toBe(UI08_DOMAINS);
});

// ---------------------------------------------------------------------------------------------
// Permissions
// ---------------------------------------------------------------------------------------------

it('references only permission keys that exist in the canonical matrix', function (): void {
    $matrix = Yaml::parseFile(ui08Path(UI08_PERMISSION_MATRIX));
    $known = array_keys($matrix['keys']);

    foreach (ui08Matrix()['domains'] as $domain) {
        $referenced = array_filter([
            $domain['permissions']['read'] ?? null,
            $domain['permissions']['mutate'] ?? null,
            ...($domain['permissions']['conditional_links'] ?? []),
        ]);

        foreach ($referenced as $key) {
            // NOTE: Pest's toContain() is VARIADIC — a second argument is another expected
            // value, not a failure message. Assert membership with in_array + a message instead.
            expect(in_array($key, $known, true))
                ->toBeTrue($domain['domain'].' references unknown permission key '.$key);
        }

        foreach ($domain['operations'] as $operation) {
            expect(in_array($operation['permission'], $known, true))
                ->toBeTrue($operation['operation_id'].' references unknown permission key '.$operation['permission']);
        }
    }
});

it('adds exactly the two authorized permission keys and no more', function (): void {
    $declared = [];

    foreach (ui08Matrix()['domains'] as $domain) {
        foreach ($domain['permissions']['new_keys'] as $key) {
            $declared[] = $key;
        }
    }

    sort($declared);
    $expected = UI08_NEW_PERMISSION_KEYS;
    sort($expected);

    expect($declared)->toBe($expected);
    expect(ui08Matrix()['baseline']['new_permission_keys_authorized'])->toBe(2);
});

it('grants the two new keys to super_admin only', function (): void {
    $matrix = Yaml::parseFile(ui08Path(UI08_PERMISSION_MATRIX));

    foreach (UI08_NEW_PERMISSION_KEYS as $key) {
        expect($matrix['keys'])->toHaveKey($key);
        expect($matrix['keys'][$key]['default_roles'])->toBe(['super_admin'], $key.' must be super_admin only');
        expect($matrix['keys'][$key]['scope'])->toBe('platform');
        expect($matrix['keys'][$key]['implementation_status'])->toBe('active');
        expect($matrix['keys'][$key]['mfa_required'])->toBeTrue();
    }
});

it('never authorizes a platform page with a merchant-tenant permission key', function (): void {
    foreach (ui08Operations() as $operation) {
        expect($operation['permission'])->toStartWith(
            'platform.',
            $operation['operation_id'].' must be authorized by a platform-scope key',
        );
    }
});

// ---------------------------------------------------------------------------------------------
// Operations
// ---------------------------------------------------------------------------------------------

it('declares 33 operations with unique operation IDs', function (): void {
    $operations = ui08Operations();
    $ids = array_map(static fn (array $operation): string => $operation['operation_id'], $operations);

    expect($operations)->toHaveCount(33);
    expect(ui08Matrix()['expected_after_all_four_domains']['new_operations'])->toBe(33);
    expect(array_unique($ids))->toHaveCount(33, 'operation IDs must be unique');
});

it('collides with no operation ID already in the committed OpenAPI contract', function (): void {
    $openapi = json_decode((string) file_get_contents(ui08Path(UI08_OPENAPI)), true, 512, JSON_THROW_ON_ERROR);

    $existing = [];
    foreach ($openapi['paths'] as $operations) {
        foreach ($operations as $operation) {
            if (is_array($operation) && isset($operation['operationId'])) {
                $existing[] = $operation['operationId'];
            }
        }
    }

    /*
     | `baseline.openapi_operations` is a HISTORICAL measurement — what the contract carried at
     | 16d544c5, before any corrective route existed — not a live invariant. What must hold at
     | every increment boundary is the arithmetic between them: the contract carries the baseline
     | plus exactly the operations this phase has actually implemented so far, and it lands on the
     | declared total once all four domains are in. An unrelated route appearing in the contract
     | fails here, and so does a corrective route that was never specified.
     */
    $baseline = ui08Matrix()['baseline']['openapi_operations'];
    $implemented = count(array_filter(
        ui08Operations(),
        static fn (array $operation): bool => $operation['implementation_state'] === 'implemented',
    ));

    /*
     | Phase UI-08 has TWO authorized sources of operation growth, and the arithmetic must name
     | both or it silently forbids one of them.
     |
     |   1. the 33 operations specified by COR-UI08-001, counted above;
     |   2. any operation itemised in `route-activation-matrix.json.new_backend_operations`, which
     |      is where a page-specific read is justified one at a time. Increment 9B added exactly
     |      one — `platform.dashboard.show` — because every other platform read is paginated, so a
     |      browser aggregating page one would have reported false platform totals on the very
     |      screen used to govern.
     |
     | Each such operation must be itemised AND actually present in the contract, so this still
     | fails for an unrelated route and still fails for a specified route that never shipped.
     */
    $itemised = ui08ItemisedOperations();
    foreach ($itemised as $operation) {
        expect($operation['itemised_justification'] ?? '')->not->toBe(
            '',
            $operation['operation_id'].' must carry a written justification',
        );
        // `toContain` is VARIADIC in Pest: a second argument is another expected value, not a
        // message. An explicit boolean keeps the message.
        expect(in_array($operation['operation_id'], $existing, true))->toBeTrue(
            $operation['operation_id'].' is itemised as added but is not in the contract',
        );
    }

    expect($existing)->toHaveCount(
        $baseline + $implemented + count($itemised),
        'the OpenAPI contract carries '.count($existing).' operations; expected the '.$baseline
        .' baseline plus the '.$implemented.' implemented corrective operations plus the '
        .count($itemised).' separately itemised operation(s)',
    );

    expect($baseline + 33)->toBe(ui08Matrix()['expected_after_all_four_domains']['openapi_operations']);

    // Collision only means something for an operation this phase has NOT yet registered. Once an
    // operation is implemented it is legitimately in the contract, and the parity case above is
    // what governs it from then on.
    foreach (ui08Operations() as $operation) {
        if ($operation['implementation_state'] !== 'planned') {
            continue;
        }

        expect(in_array($operation['operation_id'], $existing, true))
            ->toBeFalse($operation['operation_id'].' is already taken by an existing endpoint');
    }
});

it('publishes every implemented operation in the committed OpenAPI contract', function (): void {
    /*
     | docs/api/openapi.json is DERIVED from the live route collection, so it cannot precede the
     | routes. This is the parity assertion that closes the loop: once an operation is marked
     | implemented, the generated contract must actually carry it, at the specified path and method.
     | While an operation is still planned, the contract must NOT carry it.
     */
    $openapi = json_decode((string) file_get_contents(ui08Path(UI08_OPENAPI)), true, 512, JSON_THROW_ON_ERROR);

    $published = [];
    foreach ($openapi['paths'] as $path => $operations) {
        foreach ($operations as $method => $operation) {
            if (is_array($operation) && isset($operation['operationId'])) {
                $published[$operation['operationId']] = strtoupper((string) $method).' '.$path;
            }
        }
    }

    $missing = [];
    $premature = [];

    foreach (ui08Operations() as $operation) {
        $id = $operation['operation_id'];
        $expected = strtoupper($operation['method']).' '.$operation['path'];

        if ($operation['implementation_state'] === 'implemented') {
            if (! array_key_exists($id, $published)) {
                $missing[] = $id.' (regenerate with `php artisan servana:openapi`)';

                continue;
            }

            expect($published[$id])->toBe($expected, $id.' is published at a different path or method');

            continue;
        }

        if (array_key_exists($id, $published)) {
            $premature[] = $id;
        }
    }

    expect($missing)->toBe([], 'implemented operations absent from the OpenAPI contract');
    expect($premature)->toBe([], 'planned operations already published in the OpenAPI contract');
});

it('gives every operation a complete route-security contract', function (): void {
    foreach (ui08Operations() as $operation) {
        $id = $operation['operation_id'];

        expect($operation)->toHaveKeys([
            'operation_id', 'method', 'path', 'kind', 'permission',
            'requires_step_up', 'requires_idempotency', 'implementation_state', 'target_increment',
        ]);

        expect($operation['method'])->toBeIn(['GET', 'POST', 'PATCH', 'PUT', 'DELETE'], $id);
        expect($operation['kind'])->toBeIn(['read', 'mutation'], $id);
        expect($operation['path'])->toStartWith('/api/v1/platform/', $id.' must live under the platform prefix');
        expect($operation['implementation_state'])->toBeIn(['planned', 'implemented'], $id);
        expect($operation['target_increment'])->toBeIn([3, 4, 5, 6], $id);

        // An operationId in this repository IS the route name.
        expect($operation['operation_id'])->toStartWith('platform.', $id);

        if ($operation['kind'] === 'read') {
            expect($operation['method'])->toBe('GET', $id.' — a read is a GET');
            expect($operation['requires_step_up'])->toBeFalse($id);
            expect($operation['requires_idempotency'])->toBeFalse($id);

            continue;
        }

        // Every mutation: step-up, idempotency, a named step-up action and an audit event.
        expect($operation['method'])->not->toBe('GET', $id.' — a mutation is never a GET');
        expect($operation['requires_step_up'])->toBeTrue($id.' — every corrective mutation requires fresh step-up');
        expect($operation['requires_idempotency'])->toBeTrue($id);
        // NOTE: Pest's toHaveKey($key, $value) asserts the VALUE, not a message.
        expect(array_key_exists('step_up_action', $operation))->toBeTrue($id.' must name a step-up action');
        expect(array_key_exists('audit_event', $operation))->toBeTrue($id.' must name an audit event');
        expect($operation['audit_event'])->not->toBe('', $id);
    }
});

it('names only step-up actions the matrix itself declares', function (): void {
    $declared = array_map(
        static fn (array $action): string => $action['value'],
        ui08Matrix()['step_up_actions'],
    );

    foreach (ui08Operations() as $operation) {
        if (($operation['kind'] ?? null) !== 'mutation') {
            continue;
        }

        expect(in_array($operation['step_up_action'], $declared, true))
            ->toBeTrue($operation['operation_id'].' names undeclared step-up action '.$operation['step_up_action']);
    }

    // The one pre-existing action must be recorded as existing, and the two new ones as new.
    $byValue = [];
    foreach (ui08Matrix()['step_up_actions'] as $action) {
        $byValue[$action['value']] = $action['status'];
    }

    expect($byValue['billing_configuration'])->toBe('existing');
    expect($byValue['platform_access_administration'])->toBe('new');
    expect($byValue['platform_feature_flag_change'])->toBe('new');
});

it('specifies no mutation for the read-only subscription operations domain', function (): void {
    $domain = collect(ui08Matrix()['domains'])->firstWhere('domain', 'subscription_operations');

    expect($domain['permissions']['mutate'])->toBeNull();
    expect($domain['new_data_objects'])->toBe([]);
    expect($domain['operations'])->toHaveCount(7);

    foreach ($domain['operations'] as $operation) {
        expect($operation['kind'])->toBe('read', $operation['operation_id'].' — this surface is monitoring only');
        expect($operation['method'])->toBe('GET');
        expect($operation['permission'])->toBe('platform.merchant.view');
    }
});

// ---------------------------------------------------------------------------------------------
// Data dictionary, state machines and migrations
// ---------------------------------------------------------------------------------------------

it('points every domain at a data dictionary that exists', function (): void {
    foreach (ui08Matrix()['domains'] as $domain) {
        foreach ($domain['data_dictionary'] as $reference) {
            $file = explode('#', $reference)[0];
            expect(file_exists(ui08Path($file)))->toBeTrue($domain['domain'].' references missing dictionary '.$file);
        }

        foreach ($domain['state_machines'] as $file) {
            expect(file_exists(ui08Path($file)))->toBeTrue($domain['domain'].' references missing state machine '.$file);
        }
    }
});

it('documents every new data object in the data dictionary before any migration exists', function (): void {
    $dictionaries = '';
    foreach (['docs/architecture/data-dictionary/platform-governance.md', 'docs/architecture/data-dictionary/billing-and-wallet.md'] as $file) {
        $dictionaries .= (string) file_get_contents(ui08Path($file));
    }

    foreach (ui08Matrix()['domains'] as $domain) {
        foreach ($domain['new_data_objects'] as $table) {
            expect(str_contains($dictionaries, $table))
                ->toBeTrue($table.' must have a dictionary entry before its migration (ADR-004 §6)');
        }
    }
});

it('plans a migration for every new data object and no orphan migrations', function (): void {
    $planned = [];
    foreach (ui08Matrix()['migration_plan'] as $migration) {
        foreach ($migration['tables'] as $table) {
            $planned[] = $table;
        }

        expect($migration['owner'])->toBe('COR-UI08-001');
        expect($migration['expand_only'])->toBeTrue($migration['proposed_file'].' must be expand-only');
        expect($migration['change_type'])->toBeIn(['create', 'expand'], $migration['proposed_file']);
        expect($migration)->toHaveKeys([
            'purpose', 'backfill', 'deployment_order', 'application_compatibility',
            'rollback_strategy', 'data_retention_impact', 'security_impact',
        ]);
        expect($migration['domain'])->toBeIn(UI08_DOMAINS, $migration['proposed_file']);
    }

    foreach (ui08Matrix()['domains'] as $domain) {
        foreach ($domain['new_data_objects'] as $table) {
            expect(in_array($table, $planned, true))->toBeTrue($table.' has no planned migration');
        }
    }

    // Deployment order is a total order — two migrations may not claim the same slot.
    $orders = array_map(
        static fn (array $migration): int => $migration['deployment_order'],
        ui08Matrix()['migration_plan'],
    );
    expect(array_unique($orders))->toHaveCount(count($orders));
});

it('never edits a shipped migration', function (): void {
    // The only expand entry touches CHECK constraints via a NEW migration file.
    foreach (ui08Matrix()['migration_plan'] as $migration) {
        expect($migration['proposed_file'])->not->toMatch('/^\d{4}_\d{2}_\d{2}_/',
            'a planned migration is named by intent; it never reuses a shipped filename');
    }
});

// ---------------------------------------------------------------------------------------------
// Integration and capability boundaries
// ---------------------------------------------------------------------------------------------

it('keeps the Wallet and Refer & Earn boundaries intact', function (): void {
    $boundaries = ui08Matrix()['integration_boundaries'];

    expect($boundaries['gate_w'])->toContain('closed');

    $raw = strtolower((string) file_get_contents(ui08Path(UI08_MATRIX)));

    // No specified operation may touch provider or reward truth.
    foreach (ui08Operations() as $operation) {
        $path = strtolower($operation['path']);
        foreach (['daraja', 'safaricom', 'stk', 'provider-credential', 'callback', 'reward'] as $forbidden) {
            expect(str_contains($path, $forbidden))
                ->toBeFalse($operation['operation_id'].' crosses a partner boundary via "'.$forbidden.'"');
        }
    }

    expect($raw)->toContain('money movement');
    expect($raw)->toContain('reward calculation');
});

it('enumerates the forbidden operations it must never specify', function (): void {
    $forbidden = ui08Matrix()['forbidden_operations'];

    expect($forbidden)->not->toBeEmpty();

    $specified = array_map(
        static fn (array $operation): string => strtoupper($operation['method']).' '.$operation['path'],
        ui08Operations(),
    );

    foreach ($forbidden as $entry) {
        expect(in_array($entry, $specified, true))
            ->toBeFalse('the matrix specifies a forbidden operation: '.$entry);
    }
});

it('records the forbidden capability list on every domain', function (): void {
    foreach (ui08Matrix()['domains'] as $domain) {
        expect($domain['forbidden_capabilities'])->not->toBeEmpty($domain['domain']);
        expect($domain['backend_owner'])->toBe('COR-UI08-001');
        expect($domain['account'])->toBe('super_administrator');
        expect($domain['scope'])->toBe('platform');
    }
});

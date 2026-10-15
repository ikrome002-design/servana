<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/*
 | LOCAL-UAT-001, defect LUAT-D03 — the test suite must never run against the developer database.
 |
 | The Docker dev containers export DB_DATABASE=servana (the developer's working database).
 | tests/bootstrap.php forced the test cache, session, queue and mail drivers but not the database,
 | so a NON-parallel run (`php artisan test --filter …`) inherited `servana`, and RefreshDatabase's
 | migrate:fresh dropped every local table — it silently erased the local UAT dataset. Parallel runs
 | were already safe because Laravel gives each worker its own `<name>_test_<n>` database.
 |
 | The assertion asks PostgreSQL itself which database a RefreshDatabase test is connected to, so it
 | holds in both serial (`servana_test`) and parallel (`servana_test_<n>`) runs.
 */

it('connects database-backed tests to a dedicated test database, never the developer database', function (): void {
    $database = (string) DB::selectOne('select current_database() as name')->name;

    expect($database)->toMatch('/_test(_\d+)?$/');
});

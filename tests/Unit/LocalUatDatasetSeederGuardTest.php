<?php

declare(strict_types=1);

use Database\Seeders\LocalUat\LocalUatDatasetSeeder;
use Illuminate\Support\Facades\DB;

it('refuses local UAT seeding before any query outside the local environment', function (): void {
    $queries = [];
    DB::listen(function ($query) use (&$queries): void {
        $queries[] = $query->sql;
    });

    expect(fn () => (new LocalUatDatasetSeeder)->run())
        ->toThrow(RuntimeException::class, 'seeds only when APP_ENV=local');
    expect($queries)->toBeEmpty();
});

it('refuses local UAT seeding before any query against an unsafe database target', function (string $host, string $database, string $reason): void {
    app()->instance('env', 'local');
    config([
        'database.default' => 'pgsql',
        'database.connections.pgsql.host' => $host,
        'database.connections.pgsql.database' => $database,
    ]);
    $queries = [];
    DB::listen(function ($query) use (&$queries): void {
        $queries[] = $query->sql;
    });

    expect(fn () => (new LocalUatDatasetSeeder)->run())->toThrow(RuntimeException::class, $reason);
    expect($queries)->toBeEmpty();
})->with([
    'remote host' => ['db.example.invalid', 'servana', 'not a local development host'],
    'production database' => ['postgres', 'servana_production', 'does not look like a local development database'],
    'staging database' => ['localhost', 'servana_staging', 'does not look like a local development database'],
]);

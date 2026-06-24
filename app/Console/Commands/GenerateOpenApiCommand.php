<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\OpenApi\OpenApiGenerator;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

/**
 * Generate the committed production OpenAPI contract (Plan §23; Phase 10).
 * Wrapped by `composer api:openapi`. Deterministic — the inventory is derived
 * from the live route collection, never hand-maintained.
 */
final class GenerateOpenApiCommand extends Command
{
    /**
     * Core tables whose columns drive the contract's inferred types. The
     * maintained dedoc/scramble generator infers attribute and route-key types
     * by introspecting the live database schema, so generating against an
     * un-migrated database silently degrades the contract (ULID ids → integer,
     * booleans/counters → string, nullability lost). Refusing to write unless
     * these are present keeps the committed artifact schema-accurate and the
     * generation deterministic across every environment.
     */
    private const REQUIRED_SCHEMA_TABLES = [
        'merchant_branches',
        'staff_profiles',
        'staff_invitations',
        'branch_operating_hours',
        'audit_logs',
    ];

    protected $signature = 'servana:openapi {--path=docs/api/openapi.json : Output path relative to the project root}';

    protected $description = 'Generate docs/api/openapi.json from the live production route collection.';

    public function handle(OpenApiGenerator $generator): int
    {
        $missing = array_values(array_filter(
            self::REQUIRED_SCHEMA_TABLES,
            static fn (string $table): bool => ! Schema::hasTable($table),
        ));

        if ($missing !== []) {
            $this->error(
                'Database schema not migrated (missing: '.implode(', ', $missing).'). '
                .'Run `php artisan migrate` first — the OpenAPI contract is inferred from the '
                .'live schema by dedoc/scramble, so generating against an un-migrated database '
                .'produces a non-deterministic, type-degraded document.',
            );

            return self::FAILURE;
        }

        $relative = (string) $this->option('path');
        $path = base_path($relative);

        $directory = dirname($path);
        if (! is_dir($directory)) {
            mkdir($directory, 0775, true);
        }

        file_put_contents($path, $generator->toJson());

        $this->info('Wrote '.$relative.' ('.count($generator->productionRoutes()).' production routes).');

        return self::SUCCESS;
    }
}

<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\OpenApi\OpenApiGenerator;
use Illuminate\Console\Command;

/**
 * Generate the committed production OpenAPI contract (Plan §23; Phase 10).
 * Wrapped by `composer api:openapi`. Deterministic — the inventory is derived
 * from the live route collection, never hand-maintained.
 */
final class GenerateOpenApiCommand extends Command
{
    protected $signature = 'servana:openapi {--path=docs/api/openapi.json : Output path relative to the project root}';

    protected $description = 'Generate docs/api/openapi.json from the live production route collection.';

    public function handle(OpenApiGenerator $generator): int
    {
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

<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Health probes (Plan §22.1):
 *  - live(): dependency-free liveness — is the PHP process serving requests.
 *  - deep(): readiness — DB, Redis, cache, queue table, Meilisearch, S3.
 *
 * deep() returns 200 only when the REQUIRED dependencies (database, redis,
 * cache) are healthy; optional dependencies (meilisearch, s3) that are down
 * degrade the response but still return 200, while unconfigured optionals are
 * reported as "skipped". No credentials or exception details are ever leaked.
 */
final class HealthController extends Controller
{
    /** @var list<string> */
    private const REQUIRED = ['database', 'redis', 'cache'];

    public function live(): JsonResponse
    {
        return response()->json([
            'status' => 'ok',
            'service' => 'servana',
            'timestamp' => now()->toIso8601String(),
        ]);
    }

    public function deep(): JsonResponse
    {
        $checks = [
            'app' => ['status' => 'ok'],
            'database' => $this->probe(fn () => DB::connection()->select('select 1')),
            'redis' => $this->probe(fn () => Redis::connection()->ping()),
            'cache' => $this->probe(function (): void {
                Cache::put('__deep_health__', '1', 5);
                Cache::forget('__deep_health__');
            }),
            'queue' => $this->probe(function (): void {
                // Guarded: when the DB is unreachable, hasTable() throws — the
                // probe must report 'error', never let the exception escape and
                // turn the readiness response into a 500 that leaks config.
                if (! Schema::hasTable('jobs')) {
                    throw new \RuntimeException('jobs table is not migrated');
                }
            }),
            'meilisearch' => $this->probeMeilisearch(),
            's3' => $this->probeS3(),
        ];

        $requiredHealthy = collect(self::REQUIRED)
            ->every(fn (string $key): bool => $this->isHealthy($checks[$key]['status']));

        $allHealthy = collect($checks)
            ->every(fn (array $check): bool => $this->isHealthy($check['status']));

        $status = match (true) {
            ! $requiredHealthy => 'unhealthy',
            ! $allHealthy => 'degraded',
            default => 'ok',
        };

        return response()->json([
            'status' => $status,
            'service' => 'servana',
            'timestamp' => now()->toIso8601String(),
            'checks' => $checks,
        ], $requiredHealthy ? 200 : 503);
    }

    /**
     * @return array{status: string}
     */
    private function probe(callable $probe): array
    {
        try {
            $probe();

            return ['status' => 'ok'];
        } catch (Throwable $e) {
            report($e);

            return ['status' => 'error'];
        }
    }

    /**
     * @return array{status: string}
     */
    private function probeMeilisearch(): array
    {
        $host = (string) config('services.meilisearch.host', '');

        if ($host === '') {
            return ['status' => 'skipped'];
        }

        try {
            $response = Http::timeout(2)->get(rtrim($host, '/').'/health');

            return ['status' => $response->successful() ? 'ok' : 'error'];
        } catch (Throwable $e) {
            return ['status' => 'error'];
        }
    }

    /**
     * @return array{status: string}
     */
    private function probeS3(): array
    {
        $bucket = config('filesystems.disks.s3.bucket');

        if (blank($bucket)) {
            return ['status' => 'skipped'];
        }

        try {
            // When an endpoint is configured (MinIO/dev) do a lightweight live
            // reachability probe; otherwise report configured-disk readiness.
            if (filled(config('filesystems.disks.s3.endpoint'))) {
                Storage::disk('s3')->exists('.deep-health-probe');
            }

            return ['status' => 'ok'];
        } catch (Throwable $e) {
            return ['status' => 'error'];
        }
    }

    private function isHealthy(string $status): bool
    {
        return $status === 'ok' || $status === 'skipped';
    }
}

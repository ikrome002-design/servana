<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Hosts\AccountHostResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Health probes (Plan §22.1, §79 R7; REM-OPS-001):
 *
 *  - live(): dependency-free LIVENESS — is the PHP process serving requests. It
 *    never touches PostgreSQL, Redis, cache, queues, search or object storage.
 *  - deep(): READINESS — every dependency REQUIRED by the production runtime
 *    (database, redis, cache, s3; Redis backs cache + queue) must be healthy for
 *    a 200; any required failure returns 503. Optional dependencies (queue probe,
 *    meilisearch — search lands Phase 22) only degrade the response, never fail
 *    it. The required/optional split and the per-probe timeout are config-driven
 *    (config/servana.php `health`), so production cannot silently treat a managed
 *    dependency as optional. No credentials, hosts, buckets, SQL or exception
 *    details are ever leaked — only safe dependency names and statuses.
 */
final class HealthController extends Controller
{
    /** Dependency-free liveness — process health only. */
    public function live(): JsonResponse
    {
        return response()->json([
            'status' => 'ok',
            'service' => 'servana',
            'timestamp' => now()->toIso8601String(),
        ]);
    }

    /**
     * Host-context probe (Phase UI-02; UI/UX plan §4.7, §23).
     *
     * Answers one question for operators and the host smoke matrix: "which account
     * experience does this edge think this hostname is?". Dependency-free, like live().
     *
     * It reports only the requested host, the resolved account key, the environment and
     * the application status. It deliberately exposes no user, tenant, permission, token,
     * payment reference or private infrastructure detail, and — because resolving a host
     * grants nothing (ADR-017) — a 200 here is not evidence of any access.
     */
    public function host(Request $request, AccountHostResolver $resolver): JsonResponse
    {
        $accountHost = $resolver->resolve($request);
        $normalized = $resolver->normalize($request->headers->get('host'));

        if ($accountHost === null) {
            return response()->json([
                'status' => 'unknown_host',
                'service' => 'servana',
                'requested_host' => $normalized,
                'account_key' => null,
                'machine_host' => $resolver->isMachineHost($request),
                'environment' => app()->environment(),
            ], 421);
        }

        return response()->json([
            'status' => 'ok',
            'service' => 'servana',
            'requested_host' => $accountHost->host,
            'account_key' => $accountHost->accountKey,
            'machine_host' => false,
            'environment' => $accountHost->environment,
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
                // The production queue backend is Redis (already a REQUIRED check
                // above); this guards the database-queue fallback. When the DB is
                // unreachable hasTable() throws — report 'error', never let the
                // exception escape and leak config via a 500.
                if (! Schema::hasTable('jobs')) {
                    throw new \RuntimeException('jobs table is not migrated');
                }
            }),
            'meilisearch' => $this->probeMeilisearch(),
            's3' => $this->probeS3(),
        ];

        $required = (array) Config::get('servana.health.required_dependencies', ['database', 'redis', 'cache']);
        $requireConfigured = (bool) Config::get('servana.health.require_configured', false);

        $requiredHealthy = collect($required)->every(
            fn (string $key): bool => $this->isRequiredHealthy($checks[$key]['status'] ?? 'error', $requireConfigured),
        );

        $allHealthy = collect($checks)->every(fn (array $check): bool => $this->isOptionalHealthy($check['status']));

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
            $response = Http::timeout($this->timeout())->get(rtrim($host, '/').'/health');

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
            // When a custom endpoint is configured (MinIO/dev) do a lightweight,
            // timeout-bounded live reachability probe; otherwise (managed AWS S3)
            // report configured-disk readiness without a network round-trip.
            if (filled(config('filesystems.disks.s3.endpoint'))) {
                Storage::disk('s3')->exists('.deep-health-probe');
            }

            return ['status' => 'ok'];
        } catch (Throwable $e) {
            return ['status' => 'error'];
        }
    }

    /**
     * A REQUIRED dependency is healthy when it reports 'ok'. An unconfigured
     * required dependency ('skipped') passes only when `require_configured` is
     * off (non-production); in production an unconfigured managed dependency is a
     * misconfiguration and fails readiness. 'error' always fails.
     */
    private function isRequiredHealthy(string $status, bool $requireConfigured): bool
    {
        if ($status === 'ok') {
            return true;
        }

        if ($status === 'skipped') {
            return ! $requireConfigured;
        }

        return false;
    }

    /** Optional dependencies are healthy when ok or skipped; an error only degrades. */
    private function isOptionalHealthy(string $status): bool
    {
        return $status === 'ok' || $status === 'skipped';
    }

    private function timeout(): float
    {
        return (float) config('servana.health.probe_timeout', 2);
    }
}

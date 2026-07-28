<?php

declare(strict_types=1);

use App\Domain\Catalogue\Models\Service;
use App\Domain\Catalogue\Models\ServiceCategory;
use App\Domain\Clients\Models\Client;
use App\Domain\Merchants\Enums\MerchantUserRole;
use App\Domain\Scheduling\Enums\QueueEntryStatus;
use App\Domain\Scheduling\Models\QueueEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class)->group('performance', 'benchmark');

/*
|--------------------------------------------------------------------------
| Phase 24 - Section 72 API latency benchmark
|--------------------------------------------------------------------------
|
| §72 targets: API p95 read <= 500 ms (indexed), API p95 write <= 800 ms
| (excluding external-partner completion).
|
| These are WALL-CLOCK measurements, so unlike the query-count guards they are
| hardware-sensitive. They are therefore opt-in: the suite is skipped unless
| SERVANA_RUN_BENCHMARK=1, so ordinary CI never gates on laptop timing
| (benchmark profile §3.1). The Phase 24 proof runs them explicitly and records
| the environment alongside the numbers.
|
| Cardinality: each measured request runs against ONE fully-populated branch at
| the documented `representative` tier shape (500 clients, 20 services, 30 active
| queue entries). Branch-scoped reads are bounded by branch cardinality, so this
| is the volume a real request actually faces. Whole-database volume - which is
| what tenant-index selectivity depends on - is covered separately by the
| EXPLAIN (ANALYZE, BUFFERS) plans captured on the 15 360-row representative
| database and recorded in docs/performance/phase-24-results.md.
|
| Timing source: server-side request duration around the HTTP kernel, so
| loopback/network noise is never attributed to application latency (§3.4). No
| external partner is called: none of these paths has one.
|
*/

/** Percentile of a sample set, using nearest-rank on the sorted samples. */
function p24Percentile(array $samples, float $percentile): float
{
    sort($samples);
    $rank = (int) ceil($percentile / 100 * count($samples));

    return (float) $samples[max(0, $rank - 1)];
}

/**
 * Measure a callable repeatedly after a warm-up, returning milliseconds.
 *
 * @return array{p50: float, p95: float, p99: float, min: float, max: float, samples: int, errors: int}
 */
function p24Measure(Closure $call, int $iterations = 30, int $warmUp = 5): array
{
    $errors = 0;

    for ($i = 0; $i < $warmUp; $i++) {
        $call();
    }

    $samples = [];
    for ($i = 0; $i < $iterations; $i++) {
        $start = hrtime(true);
        $ok = $call();
        $samples[] = (hrtime(true) - $start) / 1_000_000;

        if ($ok === false) {
            $errors++;
        }
    }

    return [
        'p50' => p24Percentile($samples, 50),
        'p95' => p24Percentile($samples, 95),
        'p99' => p24Percentile($samples, 99),
        'min' => min($samples),
        'max' => max($samples),
        'samples' => count($samples),
        'errors' => $errors,
    ];
}

/** Emit a result line the proof document quotes verbatim. */
function p24Report(string $label, array $r): void
{
    fwrite(STDERR, sprintf(
        "\n[P24-BENCH] %-34s p50=%7.2fms p95=%7.2fms p99=%7.2fms min=%7.2fms max=%7.2fms n=%d errors=%d\n",
        $label,
        $r['p50'],
        $r['p95'],
        $r['p99'],
        $r['min'],
        $r['max'],
        $r['samples'],
        $r['errors'],
    ));
}

/**
 * One branch populated to the documented `representative` per-branch shape.
 *
 * @return array{scn: array<string, mixed>, actor: User}
 */
function p24RepresentativeBranch(): array
{
    $scn = queueScenario();

    // 500 clients — the representative per-branch client cardinality.
    $rows = [];
    $now = now();
    for ($i = 0; $i < 500; $i++) {
        $rows[] = [
            'ulid' => (string) Str::ulid(),
            'merchant_id' => $scn['merchant']->id,
            'branch_id' => $scn['branch']->id,
            'full_name' => 'Perf Client '.str_pad((string) $i, 4, '0', STR_PAD_LEFT),
            'phone_encrypted' => encrypt('+2547'.str_pad((string) (10_000_000 + $i), 8, '0', STR_PAD_LEFT)),
            'phone_index' => hash('sha256', 'perf-'.$i),
            'phone_last_four' => str_pad((string) ($i % 10000), 4, '0', STR_PAD_LEFT),
            'email_encrypted' => null,
            'notes' => null,
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }
    // Bulk insert: fixture cost is not part of any measurement, and per-model creation of 500
    // encrypted clients would dominate the test runtime without changing what is measured.
    foreach (array_chunk($rows, 100) as $chunk) {
        Client::insert($chunk);
    }

    // 20 services in the branch, all under ONE explicitly-created category. ServiceFactory would
    // otherwise build a category per service, and ServiceCategoryFactory draws its name through a
    // global `fake()->unique()` over a six-element pool — so the 19th service would exhaust Faker.
    $category = ServiceCategory::query()->create([
        'ulid' => (string) Str::ulid(),
        'merchant_id' => $scn['merchant']->id,
        'branch_id' => $scn['branch']->id,
        'name' => 'Bench Category',
        'sort_order' => 0,
    ]);

    Service::factory()->count(19)->create([
        'merchant_id' => $scn['merchant']->id,
        'branch_id' => $scn['branch']->id,
        'category_id' => $category->id,
    ]);

    // 30 active queue entries.
    for ($p = 1; $p <= 30; $p++) {
        $client = Client::query()->where('branch_id', $scn['branch']->id)->skip($p)->first();
        QueueEntry::factory()->atPosition($p)->create([
            'merchant_id' => $scn['merchant']->id,
            'branch_id' => $scn['branch']->id,
            'service_id' => $scn['service']->id,
            'client_id' => $client->id,
            'status' => QueueEntryStatus::Waiting,
        ]);
    }

    return ['scn' => $scn, 'actor' => $scn['frontOffice']];
}

it('meets the Section 72 read and write p95 targets on representative data', function (): void {
    ['scn' => $scn, 'actor' => $actor] = p24RepresentativeBranch();

    // Every measured endpoint must first be proven to return the expected status. A benchmark that
    // silently times 403s or 422s would report excellent latency for work never done.
    $statuses = [];
    foreach ([
        '/api/v1/clients',
        '/api/v1/clients?per_page=100',
        '/api/v1/clients?page=15',
        '/api/v1/queue-entries',
        '/api/v1/service-sessions',
        '/api/v1/appointments',
    ] as $uri) {
        $statuses[$uri] = test()->actingAs($actor, 'sanctum')->getJson($uri)->getStatusCode();
    }
    expect($statuses)->toBe(array_fill_keys(array_keys($statuses), 200), 'Precondition: '.json_encode($statuses));

    // Each endpoint is measured by its OWN Front Office principal.
    //
    // The `api` limiter allows 120 requests/minute keyed by principal (AppServiceProvider). A single
    // actor running warm-up + samples across six endpoints exhausts that budget partway through and
    // the remaining endpoints measure 429s — fast, uniform, and completely meaningless. Rather than
    // strip ThrottleRequests (which is genuinely part of the request cost being measured), each
    // endpoint gets a fresh principal so the middleware stays active and stays under its limit.
    $freshActor = function () use ($scn): User {
        [$user] = branchStaff($scn['merchant'], $scn['branch'], MerchantUserRole::FrontOffice);

        return $user;
    };

    $get = fn (string $uri): Closure => (function () use ($freshActor, $uri): Closure {
        $endpointActor = $freshActor();

        return function () use ($endpointActor, $uri): bool {
            return test()->actingAs($endpointActor, 'sanctum')->getJson($uri)->getStatusCode() === 200;
        };
    })();

    $reads = [
        'GET /clients (branch, masked)' => $get('/api/v1/clients'),
        'GET /clients?per_page=100' => $get('/api/v1/clients?per_page=100'),
        'GET /clients (page 15, deep offset)' => $get('/api/v1/clients?page=15'),
        'GET /queue-entries' => $get('/api/v1/queue-entries'),
        'GET /service-sessions' => $get('/api/v1/service-sessions'),
        'GET /appointments' => $get('/api/v1/appointments'),
    ];

    $readResults = [];
    foreach ($reads as $label => $call) {
        $result = p24Measure($call);
        p24Report($label, $result);
        $readResults[$label] = $result;
    }

    // Writes: an ordinary tenant-scoped create. No external partner is involved in this path.
    $writeActor = $freshActor();
    $seq = 0;
    $writeResult = p24Measure(function () use ($writeActor, &$seq): bool {
        $seq++;

        return test()->actingAs($writeActor, 'sanctum')->postJson('/api/v1/clients', [
            'full_name' => 'Bench Client '.$seq,
            'phone' => '+25472'.str_pad((string) $seq, 7, '0', STR_PAD_LEFT),
        ])->getStatusCode() === 201;
    }, iterations: 20, warmUp: 3);
    p24Report('POST /clients (write)', $writeResult);

    foreach ($readResults as $label => $result) {
        expect($result['errors'])->toBe(0, "{$label} produced errors");
        expect($result['p95'])->toBeLessThanOrEqual(
            500.0,
            sprintf('%s p95 %.2f ms exceeds the Section 72 indexed-read target of 500 ms.', $label, $result['p95']),
        );
    }

    expect($writeResult['errors'])->toBe(0, 'write path produced errors');
    expect($writeResult['p95'])->toBeLessThanOrEqual(
        800.0,
        sprintf('write p95 %.2f ms exceeds the Section 72 target of 800 ms.', $writeResult['p95']),
    );
})->skip(
    fn (): bool => env('SERVANA_RUN_BENCHMARK') !== '1',
    'Wall-clock benchmark: set SERVANA_RUN_BENCHMARK=1 to run (Phase 24 proof only, never gated in CI).',
);

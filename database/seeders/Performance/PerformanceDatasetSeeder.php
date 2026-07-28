<?php

declare(strict_types=1);

namespace Database\Seeders\Performance;

use App\Domain\Branches\Models\MerchantBranch;
use App\Domain\Catalogue\Models\Service;
use App\Domain\Catalogue\Models\ServiceCategory;
use App\Domain\Catalogue\Models\ServicePersonnelEligibility;
use App\Domain\Clients\Models\Client;
use App\Domain\Hr\Models\StaffProfile;
use App\Domain\Merchants\Models\Merchant;
use App\Domain\Merchants\Models\MerchantUser;
use App\Domain\Scheduling\Enums\QueueEntryStatus;
use App\Domain\Scheduling\Enums\ServiceSessionStatus;
use App\Domain\Scheduling\Models\PersonnelAvailability;
use App\Domain\Scheduling\Models\QueueEntry;
use App\Domain\Scheduling\Models\ServiceSession;
use App\Domain\Scheduling\Models\WalkIn;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Phase 24 deterministic performance dataset (Plan §72; benchmark profile
 * `docs/performance/phase-24-benchmark-profile.md`).
 *
 * Builds a tenant-separated, multi-branch dataset from the repository's own factories, so it can
 * never drift from the schema or bypass a model invariant. Three documented tiers exist:
 *
 *   baseline       - smallest volume where an N+1 is still distinguishable from a constant-query
 *                    implementation; the tier deterministic query-count budgets assert against.
 *   representative - the tier the Section 72 p95 targets are verified on.
 *   stress         - a deliberate multiple, used for query-plan stability only (never pass/fail).
 *
 * The tiers are engineering constructs with a stated basis, NOT a business forecast - Servana has
 * not launched and Plan §77 forbids copying production data into development.
 *
 * SAFETY: this seeder refuses to run outside `testing`/`local`, and refuses to run against a
 * database whose name does not look disposable, so it can never pollute a normal developer database
 * or reach production. It is deliberately NOT called by `DatabaseSeeder`.
 *
 * Determinism: row counts, relationship shapes and the per-branch composition are fully determined
 * by the tier. Faker is seeded so generated attribute values repeat run to run. ULIDs and encrypted
 * columns remain random by construction (they are opaque identifiers/ciphertext), which does not
 * affect any measurement - what is measured is row counts, query counts, plans and latency.
 */
final class PerformanceDatasetSeeder extends Seeder
{
    /** Deterministic Faker seed, so two runs generate the same attribute values. */
    private const FAKER_SEED = 24;

    /**
     * Per-tier composition. `clients`, `services`, `staff`, `queue_active`, `sessions_in_progress`
     * are PER BRANCH; `merchants` and `branches_per_merchant` shape the tenancy fan-out.
     *
     * @var array<string, array<string, int>>
     */
    public const TIERS = [
        'baseline' => [
            'merchants' => 3,
            'branches_per_merchant' => 2,
            'services' => 8,
            'staff' => 6,
            'clients' => 40,
            'queue_active' => 12,
            'sessions_in_progress' => 3,
        ],
        'representative' => [
            'merchants' => 6,
            'branches_per_merchant' => 3,
            'services' => 20,
            'staff' => 14,
            'clients' => 500,
            'queue_active' => 30,
            'sessions_in_progress' => 5,
        ],
        'stress' => [
            'merchants' => 12,
            'branches_per_merchant' => 4,
            'services' => 30,
            'staff' => 22,
            'clients' => 1500,
            'queue_active' => 60,
            'sessions_in_progress' => 8,
        ],
    ];

    /**
     * Counts actually written, keyed by table. Recorded so the published dataset profile is the
     * dataset generated rather than the dataset intended.
     *
     * @var array<string, int>
     */
    private array $written = [];

    public function run(): void
    {
        $tier = (string) config('servana.performance.tier', 'baseline');

        $this->guardEnvironment();

        if (! array_key_exists($tier, self::TIERS)) {
            throw new RuntimeException(sprintf(
                'Unknown performance tier "%s". Known tiers: %s.',
                $tier,
                implode(', ', array_keys(self::TIERS)),
            ));
        }

        fake()->seed(self::FAKER_SEED);

        $shape = self::TIERS[$tier];
        $this->command->info(sprintf('Building the "%s" performance dataset…', $tier));

        // Search syncing is suspended for the whole build.
        //
        // `config('scout.prefix')` is `servana_{APP_ENV}_`, NOT database-derived, so a dataset seeded
        // from the local container writes into the very Meilisearch indexes the developer's own
        // environment reads. Leaving Scout enabled would therefore push tens of thousands of
        // synthetic documents into the dev index — defeating, for search, exactly the isolation
        // `guardEnvironment()` provides for the database. It also made seeding vastly slower, since
        // every searchable row cost a Meilisearch round trip.
        //
        // This dataset exists to measure SQL query counts, plans and latency, none of which depend
        // on the search index. If a future phase needs to benchmark SEARCH on this dataset, run
        // `php artisan scout:import` explicitly against the disposable database and flush the index
        // afterwards — a deliberate, separate act rather than a side effect of seeding.
        $this->withoutSearchSyncing(function () use ($shape): void {
            for ($m = 0; $m < $shape['merchants']; $m++) {
                $merchant = Merchant::factory()->active()->create();
                $this->count('merchants');

                for ($b = 0; $b < $shape['branches_per_merchant']; $b++) {
                    $branch = MerchantBranch::factory()->create(['merchant_id' => $merchant->id]);
                    $this->count('merchant_branches');

                    $this->seedBranch($merchant, $branch, $shape);
                }
            }
        });

        $this->report($tier);
    }

    /**
     * Run the build with Scout syncing disabled for every searchable model the dataset touches.
     *
     * Nested `withoutSyncingToSearch` calls compose correctly (each restores the previous flag), so
     * this is safe even though the estimator itself uses the same mechanism.
     */
    private function withoutSearchSyncing(callable $callback): void
    {
        $models = [Client::class, QueueEntry::class, ServiceSession::class, StaffProfile::class];

        $wrapped = array_reduce(
            array_reverse($models),
            static fn (callable $next, string $model): callable => static fn () => $model::withoutSyncingToSearch($next),
            $callback,
        );

        $wrapped();
    }

    /**
     * @param  array<string, int>  $shape
     */
    private function seedBranch(Merchant $merchant, MerchantBranch $branch, array $shape): void
    {
        // Created directly rather than through ServiceCategoryFactory. That factory draws its name
        // with a GLOBAL `fake()->unique()` wrapped around a SIX-element pool, so a process can only
        // ever produce six categories before Faker throws OverflowException — capping the dataset at
        // six branches. Overriding `name` does not help: a factory evaluates its whole definition
        // before merging overrides, so the exhausted unique draw still runs.
        //
        // The database constraint is only BRANCH-scoped active-name uniqueness
        // (`service_categories_branch_active_name_unique`), so a deterministic per-branch name is
        // both sufficient and correct. The shared factory is deliberately left untouched — other
        // suites depend on it, and this is a harness scaling limit, not a product or schema defect.
        // Resetting Faker's unique store instead would have cleared the phone-uniqueness memory that
        // the client and staff factories rely on.
        $category = ServiceCategory::query()->create([
            'ulid' => (string) Str::ulid(),
            'merchant_id' => $merchant->id,
            'branch_id' => $branch->id,
            'name' => sprintf('Perf Category M%d B%d', $merchant->id, $branch->id),
            'sort_order' => 0,
        ]);
        $this->count('service_categories');

        /** @var list<Service> $services */
        $services = [];
        for ($i = 0; $i < $shape['services']; $i++) {
            $services[] = Service::factory()->create([
                'merchant_id' => $merchant->id,
                'branch_id' => $branch->id,
                'category_id' => $category->id,
            ]);
        }
        $this->count('services', count($services));

        /** @var list<StaffProfile> $staff */
        $staff = [];
        for ($i = 0; $i < $shape['staff']; $i++) {
            $merchantUser = MerchantUser::factory()->create([
                'merchant_id' => $merchant->id,
                'user_id' => User::factory()->create()->id,
            ]);
            $this->count('users');
            $this->count('merchant_users');

            $profile = StaffProfile::factory()->create([
                'merchant_id' => $merchant->id,
                'primary_branch_id' => $branch->id,
                'merchant_user_id' => $merchantUser->id,
            ]);
            $staff[] = $profile;

            // A full Mon-Sat recurring availability set, so the resolver has real rows to walk
            // rather than a single trivial interval.
            foreach ([1, 2, 3, 4, 5, 6] as $weekday) {
                PersonnelAvailability::factory()->create([
                    'staff_profile_id' => $profile->id,
                    'merchant_id' => $merchant->id,
                    'branch_id' => $branch->id,
                    'weekday' => $weekday,
                ]);
            }
            $this->count('personnel_availabilities', 6);
        }
        $this->count('staff_profiles', count($staff));

        // Eligibility: every staff member is eligible for roughly half the branch's services, so
        // the estimator's eligible set is a genuine subset rather than "all staff".
        $eligibilityRows = 0;
        foreach ($services as $index => $service) {
            foreach ($staff as $staffIndex => $profile) {
                if (($index + $staffIndex) % 2 !== 0) {
                    continue;
                }
                ServicePersonnelEligibility::factory()->create([
                    'service_id' => $service->id,
                    'staff_profile_id' => $profile->id,
                    'merchant_id' => $merchant->id,
                    'branch_id' => $branch->id,
                ]);
                $eligibilityRows++;
            }
        }
        $this->count('service_personnel_eligibilities', $eligibilityRows);

        /** @var list<Client> $clients */
        $clients = [];
        for ($i = 0; $i < $shape['clients']; $i++) {
            $clients[] = Client::factory()->create([
                'merchant_id' => $merchant->id,
                'branch_id' => $branch->id,
            ]);
        }
        $this->count('clients', count($clients));

        $this->seedQueue($merchant, $branch, $services, $staff, $clients, $shape);
    }

    /**
     * A realistic active queue: mostly `waiting`, a few `called`, and `sessions_in_progress`
     * entries genuinely `in_service` with a matching `in_progress` ServiceSession - which is what
     * makes the busy-projection behaviour (PH24-QUEUE-002) observable.
     *
     * @param  list<Service>  $services
     * @param  list<StaffProfile>  $staff
     * @param  list<Client>  $clients
     * @param  array<string, int>  $shape
     */
    private function seedQueue(
        Merchant $merchant,
        MerchantBranch $branch,
        array $services,
        array $staff,
        array $clients,
        array $shape,
    ): void {
        $inService = min($shape['sessions_in_progress'], count($staff));
        $now = CarbonImmutable::now();

        for ($position = 1; $position <= $shape['queue_active']; $position++) {
            $service = $services[($position - 1) % count($services)];
            $client = $clients[($position - 1) % count($clients)];

            $isInService = $position <= $inService;
            $status = $isInService
                ? QueueEntryStatus::InService
                : ($position <= $inService + 2 ? QueueEntryStatus::Called : QueueEntryStatus::Waiting);

            $walkIn = WalkIn::factory()->create([
                'merchant_id' => $merchant->id,
                'branch_id' => $branch->id,
                'client_id' => $client->id,
                'service_id' => $service->id,
            ]);
            $this->count('walk_ins');

            $attributes = [
                'merchant_id' => $merchant->id,
                'branch_id' => $branch->id,
                'client_id' => $client->id,
                'service_id' => $service->id,
                'walk_in_id' => $walkIn->id,
                'position' => $position,
                'status' => $status,
                'queued_at' => $now->subMinutes(($shape['queue_active'] - $position) * 3),
            ];

            // The queue_entries status<->timestamp CHECK constraints require the full prefix of the
            // lifecycle: a `called` entry must already be assigned, and an `in_service` entry must
            // already be assigned and called. Personnel are taken by position so no two active
            // entries in a branch hold the same staff member.
            if ($status === QueueEntryStatus::Called || $isInService) {
                $attributes['staff_profile_id'] = $staff[($position - 1) % count($staff)]->id;
                $attributes['assigned_at'] = $now->subMinutes(20);
                $attributes['called_at'] = $now->subMinutes(15);
            }

            if ($isInService) {
                $attributes['started_at'] = $now->subMinutes(10);
            }

            $entry = QueueEntry::factory()->create($attributes);
            $this->count('queue_entries');

            if (! $isInService) {
                continue;
            }

            ServiceSession::factory()->create([
                'merchant_id' => $merchant->id,
                'branch_id' => $branch->id,
                'client_id' => $client->id,
                'service_id' => $service->id,
                'staff_profile_id' => $staff[$position - 1]->id,
                'queue_entry_id' => $entry->id,
                'status' => ServiceSessionStatus::InProgress,
                'started_at' => $now->subMinutes(10),
            ]);
            $this->count('service_sessions');
        }
    }

    /**
     * Refuse to run anywhere the dataset could do harm. Plan §77 separates staging/production data;
     * §11.3 of the phase brief requires a dedicated disposable database.
     */
    private function guardEnvironment(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException(
                'PerformanceDatasetSeeder refuses to run outside local/testing (current: '
                .app()->environment().').',
            );
        }

        $database = (string) DB::connection()->getDatabaseName();

        if (! Str::contains($database, ['perf', 'benchmark', 'test'], ignoreCase: true)) {
            throw new RuntimeException(sprintf(
                'PerformanceDatasetSeeder refuses to run against "%s": the database name must contain '
                .'"perf", "benchmark" or "test" so a normal developer database is never polluted. '
                .'Create a disposable database first.',
                $database,
            ));
        }
    }

    private function count(string $table, int $by = 1): void
    {
        $this->written[$table] = ($this->written[$table] ?? 0) + $by;
    }

    private function report(string $tier): void
    {
        ksort($this->written);
        $total = array_sum($this->written);

        $this->command->newLine();
        $this->command->info(sprintf('Performance dataset "%s" written to %s:', $tier, DB::connection()->getDatabaseName()));
        foreach ($this->written as $table => $rows) {
            $this->command->line(sprintf('  %-34s %8d', $table, $rows));
        }
        $this->command->line(sprintf('  %-34s %8d', 'TOTAL', $total));
    }

    /** @return array<string, int> row counts actually written (consumed by the harness guard test) */
    public function writtenCounts(): array
    {
        return $this->written;
    }
}

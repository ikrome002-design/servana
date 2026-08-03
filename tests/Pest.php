<?php

declare(strict_types=1);

use App\Domain\Audit\Contracts\AuditRecorder;
use App\Domain\Audit\Enums\AuditEvent;
use App\Domain\Audit\Jobs\GenerateAuditExport;
use App\Domain\Audit\Models\AuditExport;
use App\Domain\Auth\Models\MerchantUserPermissionOverride;
use App\Domain\Auth\Models\MfaCredential;
use App\Domain\Auth\Models\Permission;
use App\Domain\Auth\Seeders\PermissionSeeder;
use App\Domain\Auth\Services\MagicLinkTokenService;
use App\Domain\Auth\Support\MagicLinkBinding;
use App\Domain\Billing\Enums\BillingInterval;
use App\Domain\Billing\Enums\MerchantBillingStatus;
use App\Domain\Billing\Enums\MerchantSubscriptionStatus;
use App\Domain\Billing\Models\MerchantSubscription;
use App\Domain\Billing\Models\PlanEntitlement;
use App\Domain\Billing\Models\SubscriptionPlan;
use App\Domain\Billing\Models\SubscriptionPlanPrice;
use App\Domain\Branches\Enums\BranchDayStatus;
use App\Domain\Branches\Models\BranchDayRecord;
use App\Domain\Branches\Models\BranchOperatingHour;
use App\Domain\Branches\Models\BranchUserAssignment;
use App\Domain\Branches\Models\MerchantBranch;
use App\Domain\Catalogue\Models\Service;
use App\Domain\Catalogue\Models\ServicePersonnelEligibility;
use App\Domain\Clients\Enums\ConsentChannel;
use App\Domain\Clients\Enums\ConsentState;
use App\Domain\Clients\Models\Client;
use App\Domain\Clients\Models\ClientConsent;
use App\Domain\Compensation\Actions\CreatePayoutRunDraft;
use App\Domain\Compensation\Models\CommissionLedgerEntry;
use App\Domain\Compensation\Models\PersonnelPayoutRun;
use App\Domain\Compensation\Models\SalaryLedgerEntry;
use App\Domain\Compensation\Services\CompensationBusinessDate;
use App\Domain\Files\Enums\FileLifecycleStatus;
use App\Domain\Files\Enums\FilePurpose;
use App\Domain\Files\Enums\FileScanStatus;
use App\Domain\Files\Models\UploadedFile;
use App\Domain\Hr\Models\StaffProfile;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Merchants\Enums\MerchantStatus;
use App\Domain\Merchants\Enums\MerchantUserRole;
use App\Domain\Merchants\Models\Merchant;
use App\Domain\Merchants\Models\MerchantUser;
use App\Domain\Payments\Enums\PaymentMethod;
use App\Domain\Payments\Enums\PaymentRecordingGroupStatus;
use App\Domain\Payments\Enums\PaymentRecordStatus;
use App\Domain\Payments\Models\PaymentRecord;
use App\Domain\Payments\Models\PaymentRecordingGroup;
use App\Domain\Scheduling\Enums\QueueAssignmentMode;
use App\Domain\Scheduling\Enums\ServiceSessionStatus;
use App\Domain\Scheduling\Models\PersonnelAvailability;
use App\Domain\Scheduling\Models\ServiceSession;
use App\Http\Hosts\AccountHostRegistry;
use App\Http\Hosts\AccountHostUrlGenerator;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

/*
 | Bind the Laravel TestCase to the Feature and Unit suites so Pest tests get
 | the full application container. Per CLAUDE.md §6.13 the database-backed
 | suites run against PostgreSQL (never SQLite); Phase 1 tests are DB-less.
 */
pest()->extend(TestCase::class)->in('Feature', 'Unit');

/*
 | Shared OpenAPI test helpers. These live in Pest.php so every test file and
 | every parallel test worker can access them without depending on another
 | test file being loaded first.
 */

function committedSpec(): array
{
    return json_decode(
        (string) file_get_contents(base_path('docs/api/openapi.json')),
        true,
        flags: JSON_THROW_ON_ERROR,
    );
}

function specOperationIds(array $spec): array
{
    $ids = [];

    foreach ($spec['paths'] ?? [] as $methods) {
        foreach ($methods as $operation) {
            if (isset($operation['operationId'])) {
                $ids[] = $operation['operationId'];
            }
        }
    }

    return $ids;
}

/*
 | "Today" as the DOMAIN sees it: the `Africa/Nairobi` business day.
 |
 | CLAUDE.md §1 and Plan §59 are explicit — timestamps are UTC, but every business-DAY decision is
 | made in `Africa/Nairobi`. `app.timezone` is `UTC`, so Laravel's global `today()`/`now()` helpers
 | resolve in UTC and are THREE HOURS BEHIND the business day. Between 21:00 and 23:59 UTC the UTC
 | calendar date is still yesterday while Nairobi has already rolled over, so a fixture built from
 | `today()` is evaluated by the domain as YESTERDAY — e.g. `CompensationBusinessDate::isBackdated()`
 | returns true for a plan the test means to be effective today, and approval then fails closed with
 | "A backdated compensation change requires an impact preview before approval."
 |
 | That is a wall-clock dependency in the FIXTURE, not a product defect: the domain is correct, and
 | production code routes every business-date decision through `CompensationBusinessDate`. Any test
 | fixture whose date is compared against a business date must therefore use this helper, never the
 | UTC `today()`. (Phase 23, defect PH23-DET-001.)
 */
function businessToday(): CarbonImmutable
{
    return CarbonImmutable::now(CompensationBusinessDate::TIMEZONE)->startOfDay();
}

/*
 | Post to the API as a first-party SPA request. Sending an Origin from a
 | stateful domain makes Sanctum apply the session middleware (StartSession),
 | so endpoints that establish a session (Magic Link verify → login + session
 | regeneration, Plan §9.2) exercise the real stateful path under test.
 *
 * @param  array<string, mixed>  $data
 */
function postStateful(string $uri, array $data = []): TestResponse
{
    return test()
        ->withHeader('Origin', 'http://localhost')
        ->postJson($uri, $data);
}

/*
 |==============================================================================
 | Phase UI-03 host-binding helpers (ADR-018, ADR-019).
 |
 | Authentication is now bound to an account host, so a test that does not vary the host is not
 | testing the control. Laravel's test client builds the request from the URI and Symfony's
 | Request::create() OVERWRITES HTTP_HOST with the URI's host — so `withHeader('Host', ...)` on a
 | relative path is silently ineffective and every call would hit `localhost`. These helpers use
 | ABSOLUTE URLs for exactly that reason (the same trap AccountHostDoesNotAuthorizeTest documents).
 */

/** The absolute URL for a path on one account host, in the testing environment. */
function accountHostUrl(string $accountKey, string $path = '/'): string
{
    return app(AccountHostUrlGenerator::class)->to($accountKey, $path, 'testing');
}

/** The bare host name for an account, e.g. `finance.servana.test`. */
function accountHostName(string $accountKey): string
{
    return app(AccountHostRegistry::class)->hostForAccount($accountKey, 'testing');
}

/**
 * POST to an /api/v1 endpoint ON a specific account host, as a first-party SPA request.
 *
 * @param  array<string, mixed>  $data
 */
function postOnHost(string $accountKey, string $path, array $data = []): TestResponse
{
    $base = accountHostUrl($accountKey, '/');

    return test()
        ->withHeader('Origin', rtrim($base, '/'))
        ->postJson(rtrim($base, '/').$path, $data);
}

/**
 * Issue a fully BOUND Magic Link for a user and return the raw token.
 *
 * Replaces the Phase 5 `MagicLinkTokenService::issue($email)` call shape across the suite: an
 * unbound token can no longer exist (the database CHECK refuses one), so a test that wants a
 * usable token must say which account host it is for.
 */
function issueBoundMagicLink(
    User|string $user,
    string $accountKey = 'merchant_administrator',
    ?string $redirectPath = null,
    ?string $host = null,
): string {
    // An email is accepted for the many Phase 5 call sites that only had one; the user is then
    // REQUIRED, because the database refuses a usable token with no bound user.
    if (is_string($user)) {
        $user = User::query()->where('email', mb_strtolower(trim($user)))->firstOrFail();
    }

    return app(MagicLinkTokenService::class)->issue(new MagicLinkBinding(
        email: $user->email,
        userId: $user->id,
        accountKey: $accountKey,
        host: $host ?? accountHostName($accountKey),
        environment: 'testing',
        redirectPath: $redirectPath,
    ));
}

/** Verify a bound Magic Link on the host it was issued for. */
function verifyMagicLinkOnHost(string $rawToken, string $accountKey = 'merchant_administrator'): TestResponse
{
    return postOnHost($accountKey, '/api/v1/auth/magic-link/verify', ['token' => $rawToken]);
}

/*
 | Build a Magic-Link-eligible user (Scope §2.3 checks 2 & 4, enforced from
 | Phase 6): an active user holding an active merchant_admin membership in an
 | active merchant. Phase 5 auth tests use this so they exercise the auth flow
 | against an eligible identity now that tenancy gating is on.
 |
 * @param  array<string, mixed>  $merchantAttributes
 */
function eligibleOwner(string $email, array $merchantAttributes = []): User
{
    $user = User::factory()->create(['email' => $email]);

    $merchant = Merchant::factory()->active()->create($merchantAttributes);

    MerchantUser::factory()->create([
        'user_id' => $user->id,
        'merchant_id' => $merchant->id,
        'role' => MerchantUserRole::MerchantAdmin,
    ]);

    return $user;
}

/**
 * Active merchant + its active merchant_admin owner (Phase 7 branch/HR tests).
 *
 * @return array{0: User, 1: Merchant, 2: MerchantUser}
 */
function activeAdmin(): array
{
    $merchant = Merchant::factory()->active()->create();
    $user = User::factory()->create();
    $membership = MerchantUser::factory()->create([
        'user_id' => $user->id,
        'merchant_id' => $merchant->id,
        'role' => MerchantUserRole::MerchantAdmin,
    ]);

    return [$user, $merchant, $membership];
}

/**
 * A branch-scoped staff member (membership + staff profile) in a merchant,
 * optionally with an active branch assignment.
 *
 * @return array{0: User, 1: MerchantUser, 2: StaffProfile}
 */
function branchStaff(
    Merchant $merchant,
    MerchantBranch $branch,
    MerchantUserRole $role = MerchantUserRole::FrontOffice,
    bool $assigned = true,
): array {
    $user = User::factory()->create();
    $membership = MerchantUser::factory()->create([
        'user_id' => $user->id,
        'merchant_id' => $merchant->id,
        'role' => $role,
    ]);
    $profile = StaffProfile::factory()->create([
        'merchant_user_id' => $membership->id,
        'merchant_id' => $merchant->id,
        'primary_branch_id' => $branch->id,
    ]);

    if ($assigned) {
        BranchUserAssignment::factory()->create([
            'merchant_user_id' => $membership->id,
            'branch_id' => $branch->id,
        ]);
    }

    return [$user, $membership, $profile];
}

/**
 * A complete, valid Front-Office appointment scenario (Phase 16A): an active
 * merchant + branch with operating hours covering the test interval, a Front
 * Office actor (branch-assigned), an eligible + available Personnel member, an
 * active 60-minute service, and a branch client. The default start is a future
 * Monday 10:00 Africa/Nairobi (inside both branch hours and personnel
 * availability), so create + assign succeed; individual tests tweak as needed.
 *
 * @return array{merchant: Merchant, branch: MerchantBranch, frontOffice: User, staff: StaffProfile, staffUser: User, service: Service, client: Client, start: CarbonImmutable, weekday: int}
 */
function appointmentScenario(): array
{
    $merchant = Merchant::factory()->active()->create();
    $branch = MerchantBranch::factory()->create(['merchant_id' => $merchant->id]);

    $date = CarbonImmutable::parse('2026-07-06', 'Africa/Nairobi'); // Monday
    $weekday = $date->dayOfWeek;

    BranchOperatingHour::query()->create([
        'merchant_id' => $merchant->id,
        'branch_id' => $branch->id,
        'weekday' => $weekday,
        'opens_at' => '08:00:00',
        'closes_at' => '18:00:00',
        'is_closed' => false,
    ]);

    [$frontOffice] = branchStaff($merchant, $branch, MerchantUserRole::FrontOffice);
    [$staffUser, , $staff] = branchStaff($merchant, $branch, MerchantUserRole::Personnel);

    $service = Service::factory()->create([
        'merchant_id' => $merchant->id,
        'branch_id' => $branch->id,
        'duration_minutes' => 60,
    ]);

    ServicePersonnelEligibility::query()->create([
        'merchant_id' => $merchant->id,
        'branch_id' => $branch->id,
        'service_id' => $service->id,
        'staff_profile_id' => $staff->id,
        'active' => true,
    ]);

    PersonnelAvailability::query()->create([
        'merchant_id' => $merchant->id,
        'branch_id' => $branch->id,
        'staff_profile_id' => $staff->id,
        'type' => 'recurring',
        'weekday' => $weekday,
        'start_time' => '09:00:00',
        'end_time' => '17:00:00',
        'available' => true,
    ]);

    $client = Client::factory()->create([
        'merchant_id' => $merchant->id,
        'branch_id' => $branch->id,
    ]);

    return [
        'merchant' => $merchant,
        'branch' => $branch,
        'frontOffice' => $frontOffice,
        'staff' => $staff,
        'staffUser' => $staffUser,
        'service' => $service,
        'client' => $client,
        'start' => $date->setTime(10, 0),
        'weekday' => $weekday,
    ];
}

/**
 * A complete, valid Front-Office queue scenario (Phase 16B): an active merchant +
 * branch with an OPEN Branch Day for TODAY and the queue open, a Front Office actor
 * (branch-assigned), a Branch Manager, two eligible + currently-available Personnel
 * members, an active 30-minute service, and a branch client. Availability covers the
 * whole of today's weekday so "now" is always inside it (the queue validates the
 * "now + duration" window). Individual tests tweak capacity/availability as needed.
 *
 * @return array{merchant: Merchant, branch: MerchantBranch, frontOffice: User, branchManager: User, staff: StaffProfile, staffUser: User, staff2: StaffProfile, staff2User: User, service: Service, client: Client, day: BranchDayRecord, weekday: int}
 */
function queueScenario(?int $capacity = null): array
{
    $merchant = Merchant::factory()->active()->create();
    $branch = MerchantBranch::factory()->create(['merchant_id' => $merchant->id]);

    $now = CarbonImmutable::now('Africa/Nairobi');
    $weekday = $now->dayOfWeek;

    BranchOperatingHour::query()->create([
        'merchant_id' => $merchant->id,
        'branch_id' => $branch->id,
        'weekday' => $weekday,
        'opens_at' => '00:00:00',
        'closes_at' => '23:59:00',
        'is_closed' => false,
    ]);

    [$frontOffice] = branchStaff($merchant, $branch, MerchantUserRole::FrontOffice);
    [$branchManager] = branchStaff($merchant, $branch, MerchantUserRole::BranchManager);
    [$staffUser, , $staff] = branchStaff($merchant, $branch, MerchantUserRole::Personnel);
    [$staff2User, , $staff2] = branchStaff($merchant, $branch, MerchantUserRole::Personnel);

    $service = Service::factory()->create([
        'merchant_id' => $merchant->id,
        'branch_id' => $branch->id,
        'duration_minutes' => 30,
    ]);

    foreach ([$staff, $staff2] as $member) {
        ServicePersonnelEligibility::query()->create([
            'merchant_id' => $merchant->id,
            'branch_id' => $branch->id,
            'service_id' => $service->id,
            'staff_profile_id' => $member->id,
            'active' => true,
        ]);
        PersonnelAvailability::query()->create([
            'merchant_id' => $merchant->id,
            'branch_id' => $branch->id,
            'staff_profile_id' => $member->id,
            'type' => 'recurring',
            'weekday' => $weekday,
            'start_time' => '00:00:00',
            'end_time' => '23:59:00',
            'available' => true,
        ]);
    }

    $client = Client::factory()->create([
        'merchant_id' => $merchant->id,
        'branch_id' => $branch->id,
    ]);

    $day = BranchDayRecord::query()->create([
        'merchant_id' => $merchant->id,
        'branch_id' => $branch->id,
        'business_date' => $now->toDateString(),
        'status' => BranchDayStatus::Open,
        'queue_is_open' => true,
        'queue_capacity' => $capacity,
        'queue_default_assignment_mode' => QueueAssignmentMode::NextAvailable,
    ]);

    return [
        'merchant' => $merchant,
        'branch' => $branch,
        'frontOffice' => $frontOffice,
        'branchManager' => $branchManager,
        'staff' => $staff,
        'staffUser' => $staffUser,
        'staff2' => $staff2,
        'staff2User' => $staff2User,
        'service' => $service,
        'client' => $client,
        'day' => $day,
        'weekday' => $weekday,
    ];
}

/**
 * A user holding one active membership of the given role in an active merchant
 * (R3 MFA tests). For a Finance member (mandatory MFA) this is the standard way
 * to get a privileged non-admin identity.
 *
 * @return array{0: User, 1: Merchant, 2: MerchantUser}
 */
function memberWithRole(MerchantUserRole $role, ?Merchant $merchant = null): array
{
    $merchant ??= Merchant::factory()->active()->create();
    $user = User::factory()->create();
    $membership = MerchantUser::factory()->create([
        'user_id' => $user->id,
        'merchant_id' => $merchant->id,
        'role' => $role,
    ]);

    return [$user, $merchant, $membership];
}

/**
 * A confirmed TOTP credential for a user, returning [credential, plaintext
 * secret]. The secret is encrypted at rest by the `encrypted` cast; the returned
 * plaintext is used by tests to compute valid OTPs.
 *
 * @return array{0: MfaCredential, 1: string}
 */
function confirmedTotp(User $user): array
{
    $secret = (new Google2FA)->generateSecretKey();
    $credential = MfaCredential::factory()->confirmed()->create([
        'user_id' => $user->id,
        'secret_encrypted' => $secret,
    ]);

    return [$credential, $secret];
}

/** A currently-valid 6-digit TOTP for the given base32 secret. */
function totpCode(string $secret): string
{
    return (new Google2FA)->getCurrentOtp($secret);
}

/**
 * Forensic + route metadata for a direct IdempotencyStore claim (R4 tests).
 *
 * @param  array<string, mixed>  $overrides
 * @return array{actor_user_id: int|null, merchant_id: int|null, branch_id: int|null, route_name: string, http_method: string, request_content_type: string|null}
 */
function idempotencyMeta(array $overrides = []): array
{
    return array_merge([
        'actor_user_id' => null,
        'merchant_id' => null,
        'branch_id' => null,
        'route_name' => 'testing.idempotency.financial',
        'http_method' => 'POST',
        'request_content_type' => 'application/json',
    ], $overrides);
}

/*
 | Shared queue API test helper. This lives in Pest.php so every queue
 | test file and every parallel worker can access it without depending
 | on QueueApiTest.php being loaded first.
 */

/** Create a walk-in over the API as the Front Office actor. */
function createWalkIn(array $scn, array $overrides = []): TestResponse
{
    return test()->actingAs($scn['frontOffice'], 'sanctum')->postJson('/api/v1/walk-ins', array_merge([
        'assignment_mode' => 'next_available',
        'service' => $scn['service']->ulid,
        'client' => $scn['client']->ulid,
    ], $overrides));
}

/**
 * Drive a walk-in through assign → call → start over the API (Phase 16C: the start
 * couples a created+started service session onto the queue called → in_service
 * transition). Returns the queue-entry ULID and the start response. Lives in Pest.php
 * so every service-session test file and parallel worker can use it.
 *
 * @param  array<string, mixed>  $createOverrides
 * @return array{ulid: string, start: TestResponse}
 */
function startQueueSession(array $scn, array $createOverrides = []): array
{
    $ulid = (string) createWalkIn($scn, $createOverrides)->json('data.id');

    test()->actingAs($scn['frontOffice'], 'sanctum')
        ->postJson("/api/v1/queue-entries/{$ulid}/call")->assertOk();

    $start = test()->actingAs($scn['frontOffice'], 'sanctum')
        ->postJson("/api/v1/queue-entries/{$ulid}/start");

    return ['ulid' => $ulid, 'start' => $start];
}

/*
 | Shared file-domain test helpers (Phase 10F). These live in Pest.php so every
 | test file and every parallel worker can use them without depending on another
 | test file being loaded first.
 */

/** Raw PNG bytes (GD) for a small valid image. */
function pngBytes(int $w = 4, int $h = 4): string
{
    $img = imagecreatetruecolor($w, $h);
    ob_start();
    imagepng($img);
    $bytes = (string) ob_get_clean();
    imagedestroy($img);

    return $bytes;
}

/** A pending/quarantined image file with a real PNG object on the faked disk. */
function quarantinedImage(): UploadedFile
{
    $disk = (string) config('files.disk');
    Storage::fake($disk);

    $file = UploadedFile::factory()->create([
        'storage_disk' => $disk,
        'quarantine_path' => 'quarantine/'.Str::ulid(),
        'detected_mime_type' => 'image/png',
        'scan_status' => FileScanStatus::Pending->value,
        'lifecycle_status' => FileLifecycleStatus::Quarantined->value,
    ]);
    Storage::disk($disk)->put($file->quarantine_path, pngBytes());

    return $file;
}

/** An available file with a real final object on the faked disk. */
function availableFile(int $merchantId, FilePurpose $purpose, ?int $ownerUserId = null, ?int $branchId = null): UploadedFile
{
    $disk = (string) config('files.disk');
    Storage::fake($disk);

    $file = UploadedFile::factory()->available()->create([
        'merchant_id' => $merchantId,
        'branch_id' => $branchId,
        'owner_user_id' => $ownerUserId,
        'purpose' => $purpose->value,
        'storage_disk' => $disk,
    ]);
    Storage::disk($disk)->put((string) $file->final_path, 'final-bytes');

    return $file;
}

/**
 * Phase 17 invoicing scenario: a branch (code `KIL`) + a same-merchant client, a
 * priced service (optionally with a legacy preferred-personnel fee), a staff member,
 * and a Front Office actor. Action-level invoice tests build on this; HTTP tests use
 * queueScenario() for real role permissions.
 *
 * @return array{branch: MerchantBranch, merchantId: int, client: Client, service: Service, staff: StaffProfile, actor: User}
 */
function invoiceScenario(int $servicePriceMinor = 500000, ?int $preferredFeeMinor = null): array
{
    $branch = MerchantBranch::factory()->create(['code' => 'KIL']);
    $merchantId = $branch->merchant_id;
    $client = Client::factory()->create(['merchant_id' => $merchantId, 'branch_id' => $branch->id]);
    $service = Service::factory()->create([
        'merchant_id' => $merchantId,
        'branch_id' => $branch->id,
        'price_minor' => $servicePriceMinor,
        'preferred_personnel_fee_minor' => $preferredFeeMinor,
        'currency' => 'KES',
    ]);
    $staff = StaffProfile::factory()->create(['merchant_id' => $merchantId, 'primary_branch_id' => $branch->id]);
    $actor = User::factory()->create();

    return compact('branch', 'merchantId', 'client', 'service', 'staff', 'actor');
}

/**
 * A completed, un-invoiced service session for an {@see invoiceScenario()} client.
 *
 * @param  array{branch: MerchantBranch, merchantId: int, client: Client, service: Service, staff: StaffProfile, actor: User}  $scn
 */
function completedSessionFor(array $scn, ?bool $preferredHonored = null): ServiceSession
{
    return ServiceSession::factory()->completed()->create([
        'merchant_id' => $scn['merchantId'],
        'branch_id' => $scn['branch']->id,
        'client_id' => $scn['client']->id,
        'service_id' => $scn['service']->id,
        'staff_profile_id' => $scn['staff']->id,
        'queue_entry_id' => null,
        'preferred_personnel_honored' => $preferredHonored,
    ]);
}

/**
 * An issued invoice + Front Office maker + Finance checker for Phase 18A payment
 * recording. Built on {@see queueScenario()} (merchant/branch/frontOffice/client).
 *
 * @return array{merchant: Merchant, branch: MerchantBranch, client: Client, frontOffice: User, finance: User, invoice: Invoice}
 */
function paymentScenario(int $invoiceTotalMinor = 500000): array
{
    $scn = queueScenario();
    [$finance] = branchStaff($scn['merchant'], $scn['branch'], MerchantUserRole::Finance);

    $invoice = Invoice::factory()->issued($invoiceTotalMinor)->create([
        'merchant_id' => $scn['merchant']->id,
        'branch_id' => $scn['branch']->id,
        'client_id' => $scn['client']->id,
        'subtotal_minor' => $invoiceTotalMinor,
        'total_minor' => $invoiceTotalMinor,
    ]);

    return [
        'merchant' => $scn['merchant'],
        'branch' => $scn['branch'],
        'client' => $scn['client'],
        'frontOffice' => $scn['frontOffice'],
        'finance' => $finance,
        'invoice' => $invoice,
    ];
}

/**
 * POST a payment recording group as $actor against $invoiceUlid with an
 * Idempotency-Key (defaulted). $components is a list of component payloads.
 *
 * @param  list<array<string, mixed>>  $components
 */
function recordPaymentGroup(User $actor, string $invoiceUlid, array $components, ?string $key = null, string $suffix = ''): TestResponse
{
    return test()->actingAs($actor, 'sanctum')
        ->withHeader('Idempotency-Key', $key ?? (string) Str::uuid())
        ->postJson("/api/v1/invoices/{$invoiceUlid}/payment-recording-groups{$suffix}", ['components' => $components]);
}

/**
 * Grant a permission override on a membership (Phase 18B helper). Used to give a
 * DISTINCT Finance membership the grantable refund.approve / refund.finalize keys.
 */
function grantOverride(MerchantUser $membership, string $permissionKey): void
{
    // Default permissions resolve from the registry, so most feature tests never seed
    // the permissions catalogue — but a DB override references permissions.id, so seed
    // the catalogue on first use.
    if (Permission::query()->count() === 0) {
        test()->seed(PermissionSeeder::class);
    }

    $permission = Permission::query()->where('key', $permissionKey)->firstOrFail();

    MerchantUserPermissionOverride::query()->updateOrCreate(
        ['merchant_user_id' => $membership->id, 'permission_id' => $permission->id],
        ['merchant_id' => $membership->merchant_id, 'effect' => 'grant'],
    );
}

/**
 * Record a group as Front Office (maker) and return its ULID (Phase 18B helper).
 *
 * @param  list<array<string, mixed>>  $components
 */
function recordPendingGroup(array $scn, array $components): string
{
    return (string) recordPaymentGroup($scn['frontOffice'], $scn['invoice']->ulid, $components)
        ->assertCreated()
        ->json('data.id');
}

/**
 * POST a whole-group validation as $actor (Finance checker) with an Idempotency-Key
 * (defaulted). Phase 18B `financial_mutation`.
 */
function validatePaymentGroup(User $actor, string $groupUlid, ?string $key = null): TestResponse
{
    return test()->actingAs($actor, 'sanctum')
        ->withHeader('Idempotency-Key', $key ?? (string) Str::uuid())
        ->postJson("/api/v1/payment-recording-groups/{$groupUlid}/validate");
}

/**
 * A single cash payment component for the given amount.
 *
 * @return array<string, mixed>
 */
function cashComponent(int $amountMinor): array
{
    return ['method' => 'cash', 'amount_minor' => $amountMinor];
}

/**
 * A referenced (non-cash) payment component.
 *
 * @return array<string, mixed>
 */
function referencedComponent(int $amountMinor, string $method = 'mpesa_offline', string $reference = 'QGX7YT1ABC'): array
{
    return ['method' => $method, 'amount_minor' => $amountMinor, 'reference' => $reference];
}

/*
 | Shared cash-up (Phase 18B) test helpers. Live in Pest.php so every cash-up /
 | day-close test file and parallel worker can use them without a load-order
 | dependency between test files.
 */

/** Today's Africa/Nairobi business date. */
function cashUpBusinessDate(): string
{
    return CarbonImmutable::now('Africa/Nairobi')->toDateString();
}

/**
 * A payment component of $method paid today anchored on a same-tenant group, for the
 * cash-up scenario. Defaults to a VALIDATED component; pass a status for others.
 *
 * @param  array{merchant: Merchant, branch: MerchantBranch, invoice: Invoice}  $scn
 */
function cashUpComponent(
    array $scn,
    PaymentMethod $method,
    int $amountMinor,
    PaymentRecordStatus $status = PaymentRecordStatus::Validated,
): PaymentRecord {
    $validated = $status === PaymentRecordStatus::Validated;
    $groupStatus = $validated
        ? PaymentRecordingGroupStatus::Validated
        : PaymentRecordingGroupStatus::PendingValidation;

    $group = PaymentRecordingGroup::factory()->create([
        'merchant_id' => $scn['merchant']->id,
        'branch_id' => $scn['branch']->id,
        'invoice_id' => $scn['invoice']->id,
        'total_amount_minor' => $amountMinor,
        'currency' => 'KES',
        'status' => $groupStatus,
        'submitted_for_validation_at' => CarbonImmutable::now(),
        'validated_at' => $validated ? CarbonImmutable::now() : null,
    ]);

    $reference = $method->requiresReference() ? strtoupper(Str::random(10)) : null;

    return PaymentRecord::factory()->create([
        'payment_recording_group_id' => $group->id,
        'merchant_id' => $scn['merchant']->id,
        'branch_id' => $scn['branch']->id,
        'invoice_id' => $scn['invoice']->id,
        'method' => $method,
        'amount_minor' => $amountMinor,
        'reference_normalized' => $reference,
        'reference_display_encrypted' => $reference,
        'validated_amount_minor' => $validated ? $amountMinor : null,
        'status' => $status,
        // Store the INSTANT, exactly as the production recording path does
        // (PaymentRecordingGroupController -> CarbonImmutable::now()). `paid_at` is cast
        // 'datetime', so Laravel serializes it as a naive 'Y-m-d H:i:s' string and PostgreSQL
        // reads it in the UTC session: passing a Nairobi WALL-CLOCK here would store 22:30 as
        // 22:30 UTC, and CashUpExpectedTotalCalculator's
        // `(paid_at AT TIME ZONE 'Africa/Nairobi')::date` would then add +03:00 again and land on
        // TOMORROW's business date — making the cash-up expectations fail whenever the suite runs
        // between 21:00 and 23:59 Nairobi. now() keeps the instant correct at every hour.
        'paid_at' => CarbonImmutable::now(),
    ]);
}

/**
 * A validated payment component of $method paid today, for the cash-up scenario.
 *
 * @param  array{merchant: Merchant, branch: MerchantBranch, invoice: Invoice}  $scn
 */
function cashUpValidatedComponent(array $scn, PaymentMethod $method, int $amountMinor): PaymentRecord
{
    return cashUpComponent($scn, $method, $amountMinor, PaymentRecordStatus::Validated);
}

/** A cash-up scenario adding a Branch Manager (maker) to {@see paymentScenario()}. */
function cashUpScenario(): array
{
    $scn = paymentScenario(500000);
    [$branchManager] = branchStaff($scn['merchant'], $scn['branch'], MerchantUserRole::BranchManager);
    $scn['branchManager'] = $branchManager;

    return $scn;
}

/** POST a cash-up state action with a defaulted Idempotency-Key. */
function cashUpPost(User $actor, string $path, array $body = []): TestResponse
{
    return test()->actingAs($actor, 'sanctum')
        ->withHeader('Idempotency-Key', (string) Str::uuid())
        ->postJson($path, $body);
}

/** PUT the branch-day draft counts as the Branch Manager. */
function putDraft(array $scn, array $counts): TestResponse
{
    return test()->actingAs($scn['branchManager'], 'sanctum')
        ->putJson("/api/v1/branches/{$scn['branch']->ulid}/cash-ups/".cashUpBusinessDate(), ['counts' => $counts]);
}

/**
 * Audit-export test helpers (Phase 19; ADR-010). Defined here — not in a single
 * test file — so every parallel worker sees them (a file-local Pest function is
 * invisible to workers running other audit-export files; cf. the Phase-16B
 * createWalkIn relocation).
 *
 * Active merchant + branch + an assigned Audit user, with a few branch-scoped general
 * audit rows recorded so an export has real content.
 *
 * @return array{admin: User, merchant: Merchant, branch: MerchantBranch, audit: User}
 */
function auditExportScenario(): array
{
    [$admin, $merchant] = activeAdmin();
    $branch = MerchantBranch::factory()->create(['merchant_id' => $merchant->id]);
    [$audit] = branchStaff($merchant, $branch, MerchantUserRole::Audit);

    $recorder = app(AuditRecorder::class);
    $recorder->record(AuditEvent::BranchDayOpened, $admin, $merchant->id, $branch->id, $branch);
    $recorder->record(AuditEvent::BranchProfileUpdated, $admin, $merchant->id, $branch->id, $branch);

    return compact('admin', 'merchant', 'branch', 'audit');
}

/** Request an audit export as the Audit user with a fresh step-up. */
function requestAuditExport(User $audit, array $body): TestResponse
{
    return test()->statefulMfa(now()->getTimestamp())->actingAs($audit, 'sanctum')
        ->postJson('/api/v1/audit-exports', $body);
}

/** Run the audit-export generation job synchronously (exercises the real job). */
function runAuditExportJob(AuditExport $export): void
{
    (new GenerateAuditExport($export->id, $export->merchant_id, $export->branch_id))->handle();
}

/** Hit the signed audit-export download STREAM (the accounting point) as the Audit user. */
function streamAuditExport(User $audit, string $ulid): TestResponse
{
    $url = URL::temporarySignedRoute('audit-exports.download', now()->addMinutes(5), ['auditExport' => $ulid]);

    return test()->actingAs($audit, 'sanctum')->get($url);
}

/** A branch + one staff profile in it (Phase 20H payout/earnings shared helper). */
function payoutBranchStaff(): array
{
    $branch = MerchantBranch::factory()->create();
    $staff = StaffProfile::factory()->create([
        'merchant_id' => $branch->merchant_id,
        'primary_branch_id' => $branch->id,
    ]);

    return [$branch, $staff];
}

/** An eligible earned commission row for one staff (Phase 20H shared helper). */
function earnedCommission(MerchantBranch $branch, StaffProfile $staff, int $minor = 50000, string $currency = 'KES'): CommissionLedgerEntry
{
    return CommissionLedgerEntry::factory()->create([
        'merchant_id' => $branch->merchant_id,
        'branch_id' => $branch->id,
        'staff_profile_id' => $staff->id,
        'amount_minor' => $minor,
        'currency' => $currency,
        'earned_at' => '2026-07-15 09:00:00',
    ]);
}

/** An eligible pending salary accrual for one staff (Phase 20H shared helper). */
function pendingSalary(MerchantBranch $branch, StaffProfile $staff, int $minor = 5000000, string $currency = 'KES'): SalaryLedgerEntry
{
    return SalaryLedgerEntry::factory()->create([
        'merchant_id' => $branch->merchant_id,
        'branch_id' => $branch->id,
        'staff_profile_id' => $staff->id,
        'amount_minor' => $minor,
        'currency' => $currency,
    ]);
}

/** Create a July-2026 draft payout run for a branch through the real action (Phase 20H shared helper). */
function draftRun(MerchantBranch $branch, string $currency = 'KES'): PersonnelPayoutRun
{
    return app(CreatePayoutRunDraft::class)->handle(
        $branch, '2026-07-01', '2026-07-31', $currency, User::factory()->create(),
    );
}

/*
 |--------------------------------------------------------------------------
 | Phase 21S — Personnel bulk SMS shared helpers
 |--------------------------------------------------------------------------
 */

/**
 * A complete, valid Personnel-SMS scenario (Phase 21S): an ACTIVE merchant whose billing status
 * allows mutations, an active subscription on a plan that ENABLES the `sms` entitlement, a
 * branch-assigned Personnel member with a staff profile, and one served + opted-in client.
 *
 * "Served" means exactly what Plan §64 means: a COMPLETED service session performed by THIS staff
 * profile. Every eligibility test builds on this and removes one ingredient.
 *
 * @return array{merchant: Merchant, branch: MerchantBranch, user: User, membership: MerchantUser, staff: StaffProfile, plan: SubscriptionPlan, client: Client, service: Service}
 */
function smsScenario(bool $withSmsEntitlement = true): array
{
    $merchant = Merchant::factory()->create([
        'status' => MerchantStatus::Active,
        'billing_status' => MerchantBillingStatus::Active,
    ]);
    $branch = MerchantBranch::factory()->create(['merchant_id' => $merchant->id]);

    [$user, $membership, $staff] = branchStaff($merchant, $branch, MerchantUserRole::Personnel);

    $plan = SubscriptionPlan::factory()->create();
    PlanEntitlement::query()->create([
        'plan_id' => $plan->id,
        'entitlement_key' => 'sms',
        'limit_int' => null,
        'enabled' => $withSmsEntitlement,
    ]);
    $price = SubscriptionPlanPrice::factory()->create([
        'plan_id' => $plan->id,
        'billing_interval' => BillingInterval::Monthly,
    ]);
    MerchantSubscription::factory()->create([
        'merchant_id' => $merchant->id,
        'plan_id' => $plan->id,
        'price_id' => $price->id,
        'status' => MerchantSubscriptionStatus::Active,
        'billing_interval' => BillingInterval::Monthly,
    ]);

    $service = Service::factory()->create(['merchant_id' => $merchant->id, 'branch_id' => $branch->id]);
    $client = smsServedClient($merchant, $branch, $staff, $service);

    return compact('merchant', 'branch', 'user', 'membership', 'staff', 'plan', 'client', 'service');
}

/**
 * A client this staff profile PERSONALLY SERVED (one completed service session) and who has opted
 * in to SMS. `$consent` may be `ConsentState::OptedOut`, or null to record NO consent row at all —
 * which is deliberately different from opting out, because absence is never consent.
 */
function smsServedClient(
    Merchant $merchant,
    MerchantBranch $branch,
    StaffProfile $staff,
    Service $service,
    ?ConsentState $consent = ConsentState::OptedIn,
    ServiceSessionStatus $sessionStatus = ServiceSessionStatus::Completed,
    string $phone = '+254712345678',
): Client {
    $client = Client::factory()->withPhone($phone)->create([
        'merchant_id' => $merchant->id,
        'branch_id' => $branch->id,
    ]);

    ServiceSession::factory()->create([
        'merchant_id' => $merchant->id,
        'branch_id' => $branch->id,
        'client_id' => $client->id,
        'service_id' => $service->id,
        'staff_profile_id' => $staff->id,
        'status' => $sessionStatus,
        // service_sessions_completed_started_check: a completed session always has a start.
        'started_at' => $sessionStatus === ServiceSessionStatus::Pending ? null : now()->subDay()->subHour(),
        'completed_at' => $sessionStatus === ServiceSessionStatus::Completed ? now()->subDay() : null,
        'cancelled_at' => $sessionStatus === ServiceSessionStatus::Cancelled ? now()->subDay() : null,
        // service_sessions_cancellation_reason_check: a cancelled session always has a reason.
        'cancellation_reason' => $sessionStatus === ServiceSessionStatus::Cancelled ? 'Client did not attend.' : null,
    ]);

    if ($consent !== null) {
        ClientConsent::factory()->create([
            'merchant_id' => $merchant->id,
            'branch_id' => $branch->id,
            'client_id' => $client->id,
            'channel' => ConsentChannel::Sms,
            'state' => $consent,
        ]);
    }

    return $client;
}

/** Compose an SMS draft through the real HTTP surface and return the response. */
function smsDraft(User $actor, array $clientUlids, string $body = 'Thank you for visiting us today.'): TestResponse
{
    return test()->actingAs($actor, 'sanctum')->postJson('/api/v1/personnel/me/sms-campaigns', [
        'client_ulids' => $clientUlids,
        'message_body' => $body,
    ]);
}

/** Confirm a campaign through the real HTTP surface (financial route: Idempotency-Key required). */
function smsConfirm(User $actor, string $campaignUlid, ?string $key = null): TestResponse
{
    return test()->actingAs($actor, 'sanctum')->postJson(
        "/api/v1/personnel/me/sms-campaigns/{$campaignUlid}/confirm",
        ['acknowledged' => true],
        ['Idempotency-Key' => $key ?? (string) Str::uuid()],
    );
}

/*
 |--------------------------------------------------------------------------
 | Phase 22 — Search
 |--------------------------------------------------------------------------
 */

/**
 * A two-branch merchant with a Front-Office actor assigned to branch A ONLY, and the same client
 * name present in BOTH branches. That shape is what makes cross-branch leakage detectable: a query
 * for the name must return the branch-A row and never the branch-B row, even though both match.
 *
 * @return array{merchant: Merchant, branchA: MerchantBranch, branchB: MerchantBranch, frontOffice: User, foMembership: MerchantUser, foProfile: StaffProfile, serviceA: Service, serviceB: Service, clientA: Client, clientB: Client}
 */
function searchScenario(string $clientName = 'Amina Wanjiku'): array
{
    $merchant = Merchant::factory()->active()->create();
    $branchA = MerchantBranch::factory()->create(['merchant_id' => $merchant->id]);
    $branchB = MerchantBranch::factory()->create(['merchant_id' => $merchant->id]);

    [$frontOffice, $foMembership, $foProfile] = branchStaff($merchant, $branchA, MerchantUserRole::FrontOffice);

    $serviceA = Service::factory()->create([
        'merchant_id' => $merchant->id,
        'branch_id' => $branchA->id,
        'name' => 'Signature Braiding',
    ]);
    $serviceB = Service::factory()->create([
        'merchant_id' => $merchant->id,
        'branch_id' => $branchB->id,
        'name' => 'Signature Braiding',
    ]);

    $clientA = Client::factory()->withPhone('+254712345678')->create([
        'merchant_id' => $merchant->id,
        'branch_id' => $branchA->id,
        'full_name' => $clientName,
    ]);
    $clientB = Client::factory()->withPhone('+254733111222')->create([
        'merchant_id' => $merchant->id,
        'branch_id' => $branchB->id,
        'full_name' => $clientName,
    ]);

    return compact(
        'merchant', 'branchA', 'branchB', 'frontOffice', 'foMembership', 'foProfile',
        'serviceA', 'serviceB', 'clientA', 'clientB',
    );
}

/**
 * A DIFFERENT merchant holding a client with the same name — the cross-tenant control row.
 *
 * @return array{merchant: Merchant, branch: MerchantBranch, client: Client}
 */
function foreignSearchScenario(string $clientName = 'Amina Wanjiku'): array
{
    $merchant = Merchant::factory()->active()->create();
    $branch = MerchantBranch::factory()->create(['merchant_id' => $merchant->id]);
    $client = Client::factory()->withPhone('+254799888777')->create([
        'merchant_id' => $merchant->id,
        'branch_id' => $branch->id,
        'full_name' => $clientName,
    ]);

    return compact('merchant', 'branch', 'client');
}

/** Call the search endpoint as an actor. */
function search(User $actor, array $query): TestResponse
{
    return test()->actingAs($actor, 'sanctum')->getJson('/api/v1/search?'.http_build_query($query));
}

/**
 * Every `type` value present in a search response, deduplicated.
 *
 * @return list<string>
 */
function searchResultTypes(TestResponse $response): array
{
    /** @var array<int, array<string, mixed>> $data */
    $data = $response->json('data') ?? [];

    return array_values(array_unique(array_map(
        static fn (array $row): string => (string) ($row['type'] ?? ''),
        $data,
    )));
}

/**
 * Every `ulid` in a search response, restricted to one document type.
 *
 * @return list<string>
 */
function searchResultUlids(TestResponse $response, ?string $type = null): array
{
    /** @var array<int, array<string, mixed>> $data */
    $data = $response->json('data') ?? [];

    $rows = $type === null
        ? $data
        : array_filter($data, static fn (array $row): bool => ($row['type'] ?? null) === $type);

    return array_values(array_map(static fn (array $row): string => (string) $row['ulid'], $rows));
}

/**
 * Every file under $dir, recursively, restricted to $extensions (lowercase, no dot).
 *
 * The single enumeration used by every static-analysis guard. It uses `scandir()` recursion
 * DELIBERATELY, never `RecursiveDirectoryIterator`: on the Docker Desktop bind mount this
 * project develops against, `RecursiveDirectoryIterator` TRUNCATES directory listings
 * mid-traversal (PH23-SCAN-001). It returned **970 of 1 087** PHP files under `app/` — so every
 * guard built on it was silently scanning ~89% of the codebase while claiming to be exhaustive.
 * A security guard that can pass because it never read the offending file is worse than none.
 *
 * `sourceFileEnumerationIsExhaustive()` cross-checks this walker against Symfony Finder so the
 * under-scan can never come back silently.
 *
 * @param  list<string>  $extensions
 * @return list<string> absolute paths, sorted for deterministic output
 */
function sourceFilesUnder(string $dir, array $extensions): array
{
    if (! is_dir($dir)) {
        return [];
    }

    $files = [];
    $walk = static function (string $current) use (&$walk, &$files, $extensions): void {
        $entries = scandir($current);
        if ($entries === false) {
            throw new RuntimeException("Unable to read directory: {$current}");
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $current.'/'.$entry;
            if (is_dir($path)) {
                $walk($path);

                continue;
            }
            if (in_array(strtolower(pathinfo($path, PATHINFO_EXTENSION)), $extensions, true)) {
                $files[] = $path;
            }
        }
    };

    $walk(rtrim($dir, '/\\'));
    sort($files);

    return $files;
}

/**
 * PHP source with every comment removed.
 *
 * Phase 22 scans its own source to prove absences ("no Wallet field", "no scout:flush"), and those
 * absences are DOCUMENTED in docblocks — so scanning raw source would flag the documentation as the
 * violation. Stripping comments first makes the scan measure runtime code only.
 */
function phpCodeWithoutComments(string $source): string
{
    $code = '';

    foreach (token_get_all($source) as $token) {
        if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
            continue;
        }

        $code .= is_array($token) ? $token[1] : $token;
    }

    return $code;
}

/**
 * The Phase UI-04 design-token authority, decoded (ADR-021).
 *
 * This lives in the bootstrap rather than in `DesignTokenSchemaTest` because FOUR specs read it
 * (schema, generation parity, contrast, web app manifest). A Pest file-scope `function` is a
 * global, but only after the file declaring it is loaded: in serial every file loads, so a
 * cross-file helper resolves; under `--parallel` each ParaTest worker loads only its own slice, so
 * the same helper fatals with "Call to undefined function" in whichever worker did not happen to
 * receive the declaring file. That made the design-token suite pass serially and fail in parallel.
 *
 * @return array<string, mixed>
 */
function ui04Tokens(): array
{
    /** @var array<string, mixed> $decoded */
    $decoded = json_decode(
        (string) file_get_contents(base_path('resources/spa/src/design-system/tokens.json')),
        true,
        flags: JSON_THROW_ON_ERROR,
    );

    return $decoded;
}

/**
 * How long a Phase 22 engine test waits for a Meilisearch task.
 *
 * meilisearch-php defaults `waitForTask()` to 5000 ms. That is ample in isolation but NOT under the
 * full suite, where these tests create and delete their own per-run indexes while everything else
 * runs — the task queue backs up and a 5 s budget expires on a healthy engine. An explicit, generous
 * budget makes the engine tests load-independent instead of quietly flaky.
 */
const P22_TASK_TIMEOUT_MS = 60_000;

// ---------------------------------------------------------------------------------------------
// Phase UI-05 — content and asset pipeline contract helpers.
//
// These live here rather than in one of the UI-05 test files on purpose: `--parallel` distributes
// test FILES across processes, so a constant or helper defined in one file is simply undefined in
// the process that runs another. Every shared UI-05 symbol therefore belongs to this bootstrap,
// which every process loads.
// ---------------------------------------------------------------------------------------------

/** Where the UI-05 audit artifacts live. */
const UI05_AUDIT_DIR = 'docs/frontend/audits/ui-05';

/** The eight canonical account keys, in the registry's own order. */
const UI05_ACCOUNTS = [
    'super_administrator',
    'merchant_administrator',
    'merchant_branch',
    'merchant_human_resource',
    'merchant_finance',
    'merchant_front_office',
    'merchant_personnel',
    'merchant_audit',
];

/** The five role-specific content categories. */
const UI05_CATEGORIES = ['landing', 'data_policy', 'privacy_policy', 'terms_of_service', 'faq'];

/** Canonical source directory per category (UI/UX plan §8.2; `landing_page` has an UNDERSCORE). */
const UI05_CATEGORY_DIRECTORIES = [
    'landing' => 'docs/landing_page',
    'data_policy' => 'docs/legal/data_policy',
    'privacy_policy' => 'docs/legal/privacy_policy',
    'terms_of_service' => 'docs/legal/terms_of_service',
    'faq' => 'docs/support/faq',
];

/** The three legal categories whose text must survive byte for byte. */
const UI05_LEGAL_CATEGORIES = ['data_policy', 'privacy_policy', 'terms_of_service'];

/** The approved brand assets the UI01-ASSET-002 quarantine must never touch. */
const UI05_PROTECTED_BRAND_FILES = [
    'Logo.png',
    'favicon.ico',
    'favicon-16x16.png',
    'favicon-32x32.png',
    'apple-touch-icon.png',
    'android-chrome-192x192.png',
    'android-chrome-512x512.png',
    'site.webmanifest',
];

/**
 * Read one of the UI-05 audit artifacts.
 *
 * @return array<string, mixed>
 */
function ui05Audit(string $name): array
{
    $path = base_path(UI05_AUDIT_DIR."/{$name}.json");
    expect(file_exists($path))->toBeTrue("Missing UI-05 audit artifact: {$name}.json");

    /** @var array<string, mixed> $decoded */
    $decoded = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

    return $decoded;
}

/**
 * The curated landing-image manifest that ships under the public root.
 *
 * @return array<string, mixed>
 */
function ui05ImageManifest(): array
{
    $path = base_path('public/assets/landing_page_images/manifest.json');
    expect(file_exists($path))->toBeTrue('Missing public/assets/landing_page_images/manifest.json');

    /** @var array<string, mixed> $manifest */
    $manifest = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

    return $manifest;
}

/**
 * The reviewed UI01-ASSET-002 quarantine decision record.
 *
 * @return array<string, mixed>
 */
function ui05QuarantineRecord(): array
{
    /** @var array<string, mixed> $record */
    $record = json_decode(
        (string) file_get_contents(base_path('config/brand-asset-quarantine.json')),
        true,
        512,
        JSON_THROW_ON_ERROR,
    );

    return $record;
}

/**
 * Extract and decode the `const markdown = "…";` literal from a generated legal module.
 *
 * The emitter writes it with `JSON.stringify`, so the literal is valid JSON. Decoding it with PHP's
 * own decoder is an INDEPENDENT check that the escaping round-trips — a bug in the emitter cannot
 * mark its own homework.
 */
function ui05DecodeGeneratedMarkdown(string $modulePath): string
{
    $module = (string) file_get_contents(base_path($modulePath));

    expect(preg_match('/^const markdown = (".*");$/m', $module, $matches))->toBe(
        1,
        "No markdown literal found in {$modulePath}.",
    );

    return (string) json_decode($matches[1], false, 512, JSON_THROW_ON_ERROR);
}

/** True when a Node runtime is available to run the generators in check mode. */
function ui05NodeAvailable(): bool
{
    $output = [];
    $exitCode = 0;
    exec('node --version 2>&1', $output, $exitCode);

    return $exitCode === 0;
}

/**
 * Run one of the UI-05 Node generators and capture how it exited.
 *
 * @return array{exitCode: int, output: string}
 */
function ui05RunNode(string $script, string ...$arguments): array
{
    $command = implode(' ', array_map('escapeshellarg', ['node', base_path($script), ...$arguments])).' 2>&1';

    $output = [];
    $exitCode = 0;
    exec($command, $output, $exitCode);

    return ['exitCode' => $exitCode, 'output' => implode("\n", $output)];
}

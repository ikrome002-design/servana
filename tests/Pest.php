<?php

declare(strict_types=1);

use App\Domain\Auth\Models\MerchantUserPermissionOverride;
use App\Domain\Auth\Models\MfaCredential;
use App\Domain\Auth\Models\Permission;
use App\Domain\Auth\Seeders\PermissionSeeder;
use App\Domain\Branches\Enums\BranchDayStatus;
use App\Domain\Branches\Models\BranchDayRecord;
use App\Domain\Branches\Models\BranchOperatingHour;
use App\Domain\Branches\Models\BranchUserAssignment;
use App\Domain\Branches\Models\MerchantBranch;
use App\Domain\Catalogue\Models\Service;
use App\Domain\Catalogue\Models\ServicePersonnelEligibility;
use App\Domain\Clients\Models\Client;
use App\Domain\Files\Enums\FileLifecycleStatus;
use App\Domain\Files\Enums\FilePurpose;
use App\Domain\Files\Enums\FileScanStatus;
use App\Domain\Files\Models\UploadedFile;
use App\Domain\Hr\Models\StaffProfile;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Merchants\Enums\MerchantUserRole;
use App\Domain\Merchants\Models\Merchant;
use App\Domain\Merchants\Models\MerchantUser;
use App\Domain\Payments\Enums\PaymentMethod;
use App\Domain\Payments\Enums\PaymentRecordingGroupStatus;
use App\Domain\Payments\Enums\PaymentRecordStatus;
use App\Domain\Payments\Models\PaymentRecord;
use App\Domain\Payments\Models\PaymentRecordingGroup;
use App\Domain\Scheduling\Enums\QueueAssignmentMode;
use App\Domain\Scheduling\Models\PersonnelAvailability;
use App\Domain\Scheduling\Models\ServiceSession;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Storage;
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
        'paid_at' => CarbonImmutable::now('Africa/Nairobi'),
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

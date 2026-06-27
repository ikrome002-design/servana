<?php

declare(strict_types=1);

use App\Domain\Auth\Models\MfaCredential;
use App\Domain\Branches\Models\BranchUserAssignment;
use App\Domain\Branches\Models\MerchantBranch;
use App\Domain\Files\Enums\FileLifecycleStatus;
use App\Domain\Files\Enums\FilePurpose;
use App\Domain\Files\Enums\FileScanStatus;
use App\Domain\Files\Models\UploadedFile;
use App\Domain\Hr\Models\StaffProfile;
use App\Domain\Merchants\Enums\MerchantUserRole;
use App\Domain\Merchants\Models\Merchant;
use App\Domain\Merchants\Models\MerchantUser;
use App\Models\User;
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

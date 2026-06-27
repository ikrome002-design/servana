<?php

declare(strict_types=1);

use App\Domain\Files\Enums\FilePurpose;
use App\Domain\Files\Models\UploadedFile;
use App\Domain\Merchants\Enums\MerchantUserRole;
use App\Domain\Merchants\Models\Merchant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

uses(RefreshDatabase::class)->group('files');

// availableFile() is a shared, parallel-safe helper in tests/Pest.php.

it('lets an authorized user issue a link and download an available file', function (): void {
    [$admin, $merchant] = activeAdmin();
    $file = availableFile($merchant->id, FilePurpose::MerchantLogo);

    $url = $this->actingAs($admin, 'sanctum')
        ->postJson("/api/v1/files/{$file->ulid}/download-link")
        ->assertOk()
        ->json('data.url');

    expect($url)->toBeString();

    $download = $this->actingAs($admin, 'sanctum')->get($url);
    $download->assertOk();
    expect($download->headers->get('X-Content-Type-Options'))->toBe('nosniff');
});

it('404s a file belonging to another merchant (no existence leak)', function (): void {
    [$admin] = activeAdmin();
    $other = Merchant::factory()->active()->create();
    $file = availableFile($other->id, FilePurpose::MerchantLogo);

    $this->actingAs($admin, 'sanctum')
        ->getJson("/api/v1/files/{$file->ulid}")
        ->assertStatus(404);
});

it('denies a user who lacks the purpose permission', function (): void {
    [, $merchant] = activeAdmin();
    [$frontOffice] = memberWithRole(MerchantUserRole::FrontOffice, $merchant);
    $file = availableFile($merchant->id, FilePurpose::MerchantLogo);

    // Front office lacks merchant.profile.manage → 403.
    $this->actingAs($frontOffice, 'sanctum')
        ->postJson("/api/v1/files/{$file->ulid}/download-link")
        ->assertStatus(403)
        ->assertJsonPath('error.code', 'permission_denied');
});

it('enforces personnel own-scope on earnings statements', function (): void {
    [$admin, $merchant] = activeAdmin();
    [$personnel] = memberWithRole(MerchantUserRole::Personnel, $merchant);

    $statement = availableFile($merchant->id, FilePurpose::EarningsStatement, ownerUserId: $personnel->id);

    // The owner can access their own statement.
    $this->actingAs($personnel, 'sanctum')
        ->postJson("/api/v1/files/{$statement->ulid}/download-link")
        ->assertOk();

    // Another user (even a Merchant Admin) cannot — own-scope is owner-only, 404.
    $this->actingAs($admin, 'sanctum')
        ->getJson("/api/v1/files/{$statement->ulid}")
        ->assertStatus(404);
});

it('rejects an expired signed download URL', function (): void {
    [$admin, $merchant] = activeAdmin();
    $file = availableFile($merchant->id, FilePurpose::MerchantLogo);

    $expired = URL::temporarySignedRoute('files.download', now()->subMinute(), ['uploadedFile' => $file->ulid]);

    $this->actingAs($admin, 'sanctum')->get($expired)->assertStatus(403);
});

it('never makes a non-available file downloadable', function (): void {
    [$admin, $merchant] = activeAdmin();
    $disk = (string) config('files.disk');
    Storage::fake($disk);
    $pending = UploadedFile::factory()->create([
        'merchant_id' => $merchant->id, 'purpose' => 'merchant_logo', 'storage_disk' => $disk,
    ]);

    $this->actingAs($admin, 'sanctum')
        ->postJson("/api/v1/files/{$pending->ulid}/download-link")
        ->assertStatus(404);
});

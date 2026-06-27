<?php

declare(strict_types=1);

use App\Domain\Files\Enums\FilePurpose;
use App\Domain\Files\Models\UploadedFile;
use App\Domain\Merchants\Models\Merchant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

uses(RefreshDatabase::class)->group('files', 'isolation');

it('cannot resolve a foreign-tenant file ULID (404, no existence leak)', function (): void {
    [$admin] = activeAdmin();
    $other = Merchant::factory()->active()->create();
    $foreign = availableFile($other->id, FilePurpose::MerchantLogo);

    $this->actingAs($admin, 'sanctum')
        ->getJson("/api/v1/files/{$foreign->ulid}")
        ->assertStatus(404);

    $this->actingAs($admin, 'sanctum')
        ->postJson("/api/v1/files/{$foreign->ulid}/download-link")
        ->assertStatus(404);
});

it('404s a syntactically valid but non-existent file ULID', function (): void {
    [$admin] = activeAdmin();

    $this->actingAs($admin, 'sanctum')
        ->getJson('/api/v1/files/'.Str::ulid())
        ->assertStatus(404);
});

it('keeps a platform (null-merchant) file invisible to a merchant user', function (): void {
    [$admin] = activeAdmin();
    // A platform-generated file has no merchant; a merchant user must not see it.
    $disk = (string) config('files.disk');
    Storage::fake($disk);
    $platformFile = UploadedFile::factory()->available()->create([
        'merchant_id' => null, 'purpose' => FilePurpose::FinanceExport->value, 'storage_disk' => $disk,
    ]);

    $this->actingAs($admin, 'sanctum')
        ->getJson("/api/v1/files/{$platformFile->ulid}")
        ->assertStatus(404);
});

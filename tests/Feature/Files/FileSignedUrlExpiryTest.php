<?php

declare(strict_types=1);

use App\Domain\Files\Enums\FilePurpose;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;

uses(RefreshDatabase::class)->group('files');

it('requires a valid signature in addition to authentication', function (): void {
    [$admin, $merchant] = activeAdmin();
    $file = availableFile($merchant->id, FilePurpose::MerchantLogo);

    // Authenticated but UNSIGNED → rejected by the signed middleware.
    $this->actingAs($admin, 'sanctum')
        ->get("/api/v1/files/{$file->ulid}/download")
        ->assertStatus(403);
});

it('accepts a current signature and rejects an expired one', function (): void {
    [$admin, $merchant] = activeAdmin();
    $file = availableFile($merchant->id, FilePurpose::MerchantLogo);

    $valid = URL::temporarySignedRoute('files.download', now()->addMinutes(5), ['uploadedFile' => $file->ulid]);
    $this->actingAs($admin, 'sanctum')->get($valid)->assertOk();

    $expired = URL::temporarySignedRoute('files.download', now()->subSecond(), ['uploadedFile' => $file->ulid]);
    $this->actingAs($admin, 'sanctum')->get($expired)->assertStatus(403);
});

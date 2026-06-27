<?php

declare(strict_types=1);

use App\Domain\Files\Enums\FilePurpose;
use App\Support\Redaction\Redactor;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class)->group('files', 'security');

it('redacts file-sensitive keys before they reach any log sink', function (): void {
    $redacted = app(Redactor::class)->redactArray([
        'signature' => 'abc123signaturevalue',
        'sha256' => str_repeat('a', 64),
        'quarantine_path' => 'quarantine/01HX',
        'final_path' => 'files/01HX',
        'storage_disk' => 's3',
        'original_filename' => 'secret-contract.pdf',
        'scanner_response' => 'stream: Eicar FOUND',
        'authorization' => 'Bearer xyz',
        'purpose' => 'merchant_logo',
    ]);

    foreach (['signature', 'sha256', 'quarantine_path', 'final_path', 'storage_disk', 'original_filename', 'scanner_response', 'authorization'] as $key) {
        expect($redacted[$key])->toBe(Redactor::REDACTED);
    }
    // Non-sensitive metadata is preserved.
    expect($redacted['purpose'])->toBe('merchant_logo');
});

it('never exposes storage paths, hash or original filename in API responses', function (): void {
    [$admin, $merchant] = activeAdmin();
    $file = availableFile($merchant->id, FilePurpose::MerchantLogo);

    $data = $this->actingAs($admin, 'sanctum')
        ->getJson("/api/v1/files/{$file->ulid}")
        ->assertOk()
        ->json('data');

    foreach (['quarantine_path', 'final_path', 'storage_disk', 'sha256', 'original_filename_encrypted'] as $key) {
        expect($data)->not->toHaveKey($key);
    }
});

<?php

declare(strict_types=1);

use App\Domain\Files\Jobs\ScanUploadedFile;
use App\Domain\Files\Models\UploadedFile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile as HttpUploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class)->group('files');

beforeEach(function (): void {
    Storage::fake(config('files.disk'));
    Bus::fake([ScanUploadedFile::class]);
});

it('accepts a valid image upload, quarantines it and dispatches a scan', function (): void {
    [$admin] = activeAdmin();

    $response = $this->actingAs($admin, 'sanctum')->postJson('/api/v1/files', [
        'purpose' => 'merchant_logo',
        'file' => HttpUploadedFile::fake()->image('logo.png', 64, 64),
    ]);

    $response->assertStatus(202)
        ->assertJsonPath('data.purpose', 'merchant_logo')
        ->assertJsonPath('data.scan_status', 'pending')
        ->assertJsonPath('data.lifecycle_status', 'quarantined');

    // No storage internals or hash are ever exposed.
    expect($response->json('data'))->not->toHaveKey('quarantine_path')
        ->and($response->json('data'))->not->toHaveKey('storage_disk')
        ->and($response->json('data'))->not->toHaveKey('sha256')
        ->and($response->json('data.id'))->toHaveLength(26);

    $file = UploadedFile::query()->latest('id')->first();
    expect($file)->not->toBeNull()
        ->and($file->sha256)->toHaveLength(64);
    Storage::disk(config('files.disk'))->assertExists($file->quarantine_path);

    Bus::assertDispatched(ScanUploadedFile::class);
});

it('rejects a generated-only purpose at the request layer', function (): void {
    [$admin] = activeAdmin();

    $this->actingAs($admin, 'sanctum')->postJson('/api/v1/files', [
        'purpose' => 'finance_export',
        'file' => HttpUploadedFile::fake()->image('x.png'),
    ])->assertStatus(422)->assertJsonPath('error.code', 'validation_failed');
});

it('rejects a MIME-spoofed file (text disguised as png)', function (): void {
    [$admin] = activeAdmin();

    $this->actingAs($admin, 'sanctum')->postJson('/api/v1/files', [
        'purpose' => 'merchant_logo',
        'file' => HttpUploadedFile::fake()->createWithContent('logo.png', 'this is plainly not an image'),
    ])->assertStatus(422);

    expect(UploadedFile::query()->count())->toBe(0); // rejected bytes never stored
});

it('rejects a double-extension filename', function (): void {
    [$admin] = activeAdmin();

    $this->actingAs($admin, 'sanctum')->postJson('/api/v1/files', [
        'purpose' => 'merchant_logo',
        'file' => HttpUploadedFile::fake()->image('logo.php.png', 32, 32),
    ])->assertStatus(422);

    expect(UploadedFile::query()->count())->toBe(0);
});

it('rejects an oversized file', function (): void {
    [$admin] = activeAdmin();
    $maxKb = (int) ceil(((int) config('files.image_max_bytes')) / 1024);

    $this->actingAs($admin, 'sanctum')->postJson('/api/v1/files', [
        'purpose' => 'merchant_logo',
        'file' => HttpUploadedFile::fake()->create('big.png', $maxKb + 1024, 'image/png'),
    ])->assertStatus(422);
});

it('rejects an executable disguised with an image extension', function (): void {
    [$admin] = activeAdmin();

    $this->actingAs($admin, 'sanctum')->postJson('/api/v1/files', [
        'purpose' => 'merchant_logo',
        'file' => HttpUploadedFile::fake()->createWithContent('logo.png', "\x7fELF\x02\x01\x01".str_repeat("\x00", 64)),
    ])->assertStatus(422);

    expect(UploadedFile::query()->count())->toBe(0);
});

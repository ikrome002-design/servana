<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Files\Enums\FileLifecycleStatus;
use App\Domain\Files\Enums\FilePurpose;
use App\Domain\Files\Enums\FileScanStatus;
use App\Domain\Files\Models\UploadedFile;
use App\Domain\Merchants\Models\Merchant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<UploadedFile>
 */
class UploadedFileFactory extends Factory
{
    protected $model = UploadedFile::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $ulid = (string) Str::ulid();

        return [
            'ulid' => $ulid,
            'merchant_id' => Merchant::factory(),
            'branch_id' => null,
            'owner_user_id' => null,
            'purpose' => FilePurpose::MerchantLogo->value,
            'storage_disk' => 's3',
            'quarantine_path' => 'quarantine/'.$ulid,
            'final_path' => null,
            'original_filename_encrypted' => 'logo.png',
            'safe_download_filename' => 'logo.png',
            'declared_mime_type' => 'image/png',
            'detected_mime_type' => 'image/png',
            'extension' => 'png',
            'size_bytes' => fake()->numberBetween(1024, 1024 * 512),
            'sha256' => hash('sha256', $ulid),
            'scan_status' => FileScanStatus::Pending->value,
            'lifecycle_status' => FileLifecycleStatus::Quarantined->value,
            'retention_until' => null,
            'uploaded_by' => null,
            'available_at' => null,
            'revoked_at' => null,
        ];
    }

    public function clean(): static
    {
        return $this->state(fn (): array => ['scan_status' => FileScanStatus::Clean->value]);
    }

    public function available(): static
    {
        return $this->state(fn (array $attributes): array => [
            'scan_status' => FileScanStatus::Clean->value,
            'lifecycle_status' => FileLifecycleStatus::Available->value,
            'final_path' => 'final/'.($attributes['ulid'] ?? Str::ulid()),
            'available_at' => now(),
        ]);
    }

    public function infected(): static
    {
        return $this->state(fn (): array => ['scan_status' => FileScanStatus::Infected->value]);
    }

    public function scanFailed(): static
    {
        return $this->state(fn (): array => ['scan_status' => FileScanStatus::ScanFailed->value]);
    }

    public function purpose(FilePurpose $purpose): static
    {
        return $this->state(fn (): array => ['purpose' => $purpose->value]);
    }
}

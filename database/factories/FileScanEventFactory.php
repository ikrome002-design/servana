<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Files\Enums\FileScanResult;
use App\Domain\Files\Models\FileScanEvent;
use App\Domain\Files\Models\UploadedFile;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FileScanEvent>
 */
class FileScanEventFactory extends Factory
{
    protected $model = FileScanEvent::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'uploaded_file_id' => UploadedFile::factory(),
            'scanner' => 'clamav',
            'engine_version' => '1.4.0',
            'signature_version' => '27000',
            'result' => FileScanResult::Clean->value,
            'malware_name' => null,
            'error_code' => null,
            'scanned_at' => now(),
        ];
    }

    public function infected(string $name = 'Eicar-Test-Signature'): static
    {
        return $this->state(fn (): array => [
            'result' => FileScanResult::Infected->value,
            'malware_name' => $name,
        ]);
    }

    public function error(string $code = 'scan_error'): static
    {
        return $this->state(fn (): array => [
            'result' => FileScanResult::Error->value,
            'error_code' => $code,
        ]);
    }
}

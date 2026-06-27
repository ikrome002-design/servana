<?php

declare(strict_types=1);

namespace App\Domain\Files\Models;

use App\Domain\Files\Enums\FileScanResult;
use Database\Factories\FileScanEventFactory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One row per actual malware scan of an uploaded file (Plan §13.13; Phase 10F).
 * Append-only; scope inherited via uploaded_file_id; never route-bound. Scanner
 * raw payloads/responses are never stored — only the mapped result + safe metadata.
 *
 * @property int $id
 * @property int $uploaded_file_id
 * @property string $scanner
 * @property string|null $engine_version
 * @property string|null $signature_version
 * @property FileScanResult $result
 * @property string|null $malware_name
 * @property string|null $error_code
 * @property Carbon $scanned_at
 */
class FileScanEvent extends Model
{
    /** @use HasFactory<FileScanEventFactory> */
    use HasFactory;

    protected $table = 'file_scan_events';

    /** No created_at/updated_at — only scanned_at (per §13.13 schema). */
    public $timestamps = false;

    /** @var list<string> */
    protected $fillable = [
        'uploaded_file_id',
        'scanner',
        'engine_version',
        'signature_version',
        'result',
        'malware_name',
        'error_code',
        'scanned_at',
    ];

    /** @return Factory<FileScanEvent> */
    protected static function newFactory(): Factory
    {
        return FileScanEventFactory::new();
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'result' => FileScanResult::class,
            'scanned_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<UploadedFile, $this> */
    public function uploadedFile(): BelongsTo
    {
        return $this->belongsTo(UploadedFile::class);
    }
}

<?php

declare(strict_types=1);

namespace App\Domain\Audit\Models;

use App\Domain\Audit\Enums\AuditSeverity;
use App\Domain\Merchants\Models\Merchant;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An append-only, hash-chained audit record (Plan §7.5, §22.2).
 *
 * Rows are immutable — a database trigger blocks UPDATE/DELETE (guardrail §6.5).
 * The model disables `updated_at` (single `created_at` timestamp) and is written
 * only through DatabaseAuditRecorder, which computes the hash chain. Phase 19
 * adds chain verification, masking, and the full §5.18 event set.
 *
 * @property int $id
 * @property string $ulid
 * @property int|null $merchant_id
 * @property int|null $actor_id
 * @property string|null $actor_label
 * @property string $action
 * @property AuditSeverity $severity
 * @property string|null $auditable_type
 * @property int|null $auditable_id
 * @property array<string, mixed>|null $context
 * @property string|null $ip_address
 * @property string|null $correlation_id
 * @property string|null $previous_hash
 * @property string $hash
 */
class AuditLog extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'ulid',
        'merchant_id',
        'actor_id',
        'actor_label',
        'action',
        'severity',
        'auditable_type',
        'auditable_id',
        'context',
        'ip_address',
        'correlation_id',
        'previous_hash',
        'hash',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'severity' => AuditSeverity::class,
            'context' => 'array',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'ulid';
    }

    /** @return BelongsTo<Merchant, $this> */
    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }

    /** @return BelongsTo<User, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}

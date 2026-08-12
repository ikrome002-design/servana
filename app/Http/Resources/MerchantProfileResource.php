<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Files\Models\UploadedFile;
use App\Domain\Merchants\Models\MerchantProfile;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Merchant business profile (REM-SCR-002A).
 *
 * ULIDs only — never the internal id. The logo is exposed as the file domain's PUBLIC
 * identifier plus its safe filename; the storage disk, path and hash are `$hidden` on
 * UploadedFile and are never surfaced here. The caller fetches the bytes through the existing
 * authorized Phase 10F link endpoint, so this resource carries no URL and no signature.
 *
 * `merchant.name`/`slug`, `service_fee_tier`, `status` and every billing column are read-only
 * context, included so the screen never has to guess — the update endpoint's allowlist is what
 * makes them unwritable.
 *
 * @mixin MerchantProfile
 */
final class MerchantProfileResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $merchant = $this->merchant;
        $logo = $this->currentLogo();

        return [
            'id' => $this->ulid,

            // Editable business profile (Scope §3.2 step 2 field contract).
            'business_category' => $this->business_category,
            'contact_email' => $this->contact_email,
            'contact_phone' => $this->contact_phone,
            'receipt_display_name' => $this->receipt_display_name,
            'address' => $this->address,
            'town' => $this->town,
            'timezone' => $this->timezone,

            // Fixed at registration / owned elsewhere — displayed, never writable here.
            'country' => $this->country,
            'merchant' => $merchant === null ? null : [
                'id' => $merchant->ulid,
                'name' => $merchant->name,
                'slug' => $merchant->slug,
                'status' => $merchant->status->value,
                'service_fee_tier' => $merchant->service_fee_tier?->value,
            ],

            // Current logo: public file id + safe filename only. No path, no URL, no signature.
            'logo' => $logo === null ? null : [
                'id' => $logo->ulid,
                'filename' => $logo->safe_download_filename,
            ],
            'logo_history' => UploadedFile::query()
                ->where('merchant_id', $this->merchant_id)
                ->where('purpose', 'merchant_logo')
                ->where('lifecycle_status', 'available')
                ->where('scan_status', 'clean')
                ->latest('id')
                ->limit(10)
                ->get()
                ->map(static fn (UploadedFile $file): array => [
                    'id' => $file->ulid,
                    'filename' => $file->safe_download_filename,
                    'available_at' => $file->available_at?->toIso8601String(),
                ])->values(),
        ];
    }

    /**
     * The merchant's current logo: the most recent AVAILABLE `merchant_logo` file for this
     * tenant. Deterministic, and it needs no schema change — the legacy `logo_path` column has
     * no writer anywhere and is deliberately not used (a path would leak storage layout).
     */
    private function currentLogo(): ?UploadedFile
    {
        return UploadedFile::query()
            ->where('merchant_id', $this->merchant_id)
            ->where('purpose', 'merchant_logo')
            ->where('lifecycle_status', 'available')
            ->where('scan_status', 'clean')
            ->orderByDesc('id')
            ->first();
    }
}

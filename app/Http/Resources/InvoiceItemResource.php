<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Catalogue\Models\Service;
use App\Domain\Hr\Models\StaffProfile;
use App\Domain\Invoicing\Models\InvoiceItem;
use App\Domain\Scheduling\Models\ServiceSession;
use App\Enums\Currency;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Invoice line-item payload (Plan §13.8; guardrail §6.4; Phase 17). Exposes the item
 * ULID, the source service-session/service/personnel ULIDs, the immutable price/fee
 * snapshots as { amount, currency, formatted } money objects, and the commission
 * eligibility flag. Internal bigint ids are NEVER serialized.
 *
 * @mixin InvoiceItem
 */
final class InvoiceItemResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $currency = Currency::from($this->currency);

        return [
            'id' => $this->ulid,
            'service_session_id' => $this->whenLoaded('serviceSession', function (): ?string {
                /** @var ServiceSession|null $session */
                $session = $this->serviceSession;

                return $session === null ? null : $session->ulid;
            }),
            'service' => $this->whenLoaded('service', function (): array {
                /** @var Service $service */
                $service = $this->service;

                return ['id' => $service->ulid, 'name' => $service->name];
            }),
            'personnel' => $this->whenLoaded('personnel', function (): ?array {
                /** @var StaffProfile|null $personnel */
                $personnel = $this->personnel;

                return $personnel === null ? null : ['id' => $personnel->ulid, 'display_name' => $personnel->display_name];
            }),
            'description' => $this->description,
            'quantity' => $this->quantity,
            'unit_price' => Money::ofMinor($this->unit_price_minor, $currency)->toArray(),
            'line_total' => Money::ofMinor($this->line_total_minor, $currency)->toArray(),
            'preferred_personnel_fee' => $this->preferred_personnel_fee_minor === null
                ? null
                : Money::ofMinor($this->preferred_personnel_fee_minor, $currency)->toArray(),
            'eligible_for_commission' => $this->eligible_for_commission,
            'currency' => $this->currency,
        ];
    }
}

<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Clients\Models\Client;
use App\Domain\Messaging\Sms\Support\PhoneNumberDisplayMasker;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One served client, as offered to a Personnel user for SMS selection (Plan §64; ADR-010;
 * Phase 21S).
 *
 * THE MINIMUM NEEDED TO PICK A RECIPIENT, and nothing more:
 *   - the public ULID (the only identifier the API accepts back);
 *   - the display name;
 *   - a MASKED phone (`••• ••• 1234`) rendered through {@see PhoneNumberDisplayMasker}, which
 *     cannot return more than four digits.
 *
 * Deliberately ABSENT: the full phone, the encrypted phone, the blind index, the email (masked or
 * not), the internal numeric id, the branch/merchant ids, session history, notes, and any field
 * that would make a page of this collection usable as a contact list. This is the resource that
 * makes "no endpoint returns bulk full phone numbers" true.
 *
 * @mixin Client
 */
final class ServedClientForSmsResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->ulid,
            'full_name' => $this->full_name,
            'phone_masked' => PhoneNumberDisplayMasker::maskFromLastFour($this->phone_last_four),
        ];
    }
}

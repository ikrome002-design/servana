<?php

declare(strict_types=1);

namespace App\Domain\Clients\Actions;

use App\Domain\Audit\Contracts\AuditRecorder;
use App\Domain\Audit\Enums\AuditEvent;
use App\Domain\Branches\Models\MerchantBranch;
use App\Domain\Clients\Enums\ClientStatus;
use App\Domain\Clients\Exceptions\DuplicateClientException;
use App\Domain\Clients\Models\Client;
use App\Domain\Clients\Support\ClientContactIndex;
use App\Domain\Clients\Support\PhoneNumberNormalizer;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Create a client in a branch (Plan §35; guardrail §6.4; Phase 15A).
 *
 * Front Office authority is enforced upstream. The phone is normalized, encrypted
 * at rest (via the model cast), masked to last-four, and indexed with a keyed HMAC
 * blind index. Same-branch active-phone duplicates are rejected with a
 * deterministic 409 (checked before insert AND backstopped by the DB partial
 * unique index). The audit context carries ONLY the masked last-four — never the
 * full phone/email and never the blind index.
 */
final class CreateClient
{
    public function __construct(private readonly AuditRecorder $audit) {}

    /** @param array<string, mixed> $data */
    public function handle(MerchantBranch $branch, User $actor, array $data): Client
    {
        $phone = (string) $data['phone'];
        $normalizedPhone = PhoneNumberNormalizer::normalize($phone);
        $phoneIndex = ClientContactIndex::for($phone);

        $existing = Client::query()
            ->where('branch_id', $branch->id)
            ->where('phone_index', $phoneIndex)
            ->where('status', ClientStatus::Active->value)
            ->first();

        if ($existing !== null) {
            throw DuplicateClientException::forExisting($existing->ulid);
        }

        return DB::transaction(function () use ($branch, $actor, $data, $normalizedPhone, $phoneIndex): Client {
            $client = Client::query()->create([
                'merchant_id' => $branch->merchant_id,
                'branch_id' => $branch->id,
                'full_name' => (string) $data['full_name'],
                'phone_encrypted' => $normalizedPhone,
                'phone_index' => $phoneIndex,
                'phone_last_four' => PhoneNumberNormalizer::lastFour($normalizedPhone),
                'email_encrypted' => isset($data['email']) ? (string) $data['email'] : null,
                'notes' => isset($data['notes']) ? (string) $data['notes'] : null,
                'created_by' => $actor->id,
                'status' => ClientStatus::Active,
            ]);

            $this->audit->record(
                AuditEvent::ClientCreated,
                $actor,
                $branch->merchant_id,
                $branch->id,
                $client,
                ['client_id' => $client->ulid, 'phone_last_four' => $client->phone_last_four],
            );

            return $client;
        });
    }
}

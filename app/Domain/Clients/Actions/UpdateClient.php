<?php

declare(strict_types=1);

namespace App\Domain\Clients\Actions;

use App\Domain\Audit\Contracts\AuditRecorder;
use App\Domain\Audit\Enums\AuditEvent;
use App\Domain\Clients\Enums\ClientStatus;
use App\Domain\Clients\Exceptions\DuplicateClientException;
use App\Domain\Clients\Models\Client;
use App\Domain\Clients\Support\ClientContactIndex;
use App\Domain\Clients\Support\PhoneNumberNormalizer;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Update a client (Plan §35; guardrail §6.4; Phase 15A). Front Office authority is
 * enforced upstream. When the phone changes, the blind index + masked last-four
 * are recomputed and the same-branch active-duplicate rule is re-checked. Email is
 * re-encrypted; the blind index/ciphertext never appear in the audit context.
 */
final class UpdateClient
{
    public function __construct(private readonly AuditRecorder $audit) {}

    /** @param array<string, mixed> $data */
    public function handle(Client $client, User $actor, array $data): Client
    {
        if (array_key_exists('phone', $data) && (string) $data['phone'] !== '') {
            $phone = (string) $data['phone'];
            $normalizedPhone = PhoneNumberNormalizer::normalize($phone);
            $phoneIndex = ClientContactIndex::for($phone);

            if ($phoneIndex !== $client->phone_index) {
                $duplicate = Client::query()
                    ->where('branch_id', $client->branch_id)
                    ->where('phone_index', $phoneIndex)
                    ->where('status', ClientStatus::Active->value)
                    ->whereKeyNot($client->id)
                    ->first();

                if ($duplicate !== null) {
                    throw DuplicateClientException::forExisting($duplicate->ulid);
                }

                $client->phone_encrypted = $normalizedPhone;
                $client->phone_index = $phoneIndex;
                $client->phone_last_four = PhoneNumberNormalizer::lastFour($normalizedPhone);
            }
        }

        return DB::transaction(function () use ($client, $actor, $data): Client {
            if (array_key_exists('full_name', $data)) {
                $client->full_name = (string) $data['full_name'];
            }
            if (array_key_exists('email', $data)) {
                $client->email_encrypted = $data['email'] === null ? null : (string) $data['email'];
            }
            if (array_key_exists('notes', $data)) {
                $client->notes = $data['notes'] === null ? null : (string) $data['notes'];
            }

            $client->save();

            $this->audit->record(
                AuditEvent::ClientUpdated,
                $actor,
                $client->merchant_id,
                $client->branch_id,
                $client,
                ['client_id' => $client->ulid, 'phone_last_four' => $client->phone_last_four],
            );

            return $client;
        });
    }
}

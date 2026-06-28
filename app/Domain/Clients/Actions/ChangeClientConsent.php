<?php

declare(strict_types=1);

namespace App\Domain\Clients\Actions;

use App\Domain\Audit\Contracts\AuditRecorder;
use App\Domain\Audit\Enums\AuditEvent;
use App\Domain\Clients\Enums\ConsentChannel;
use App\Domain\Clients\Enums\ConsentState;
use App\Domain\Clients\Models\Client;
use App\Domain\Clients\Models\ClientConsent;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Record/change a client's SMS consent (Plan §35; Phase 15A). Front Office
 * authority is enforced upstream. One current state per (client, sms): the row is
 * upserted and `changed_at` advanced. NO SMS is sent in Phase 15A (Phase 21S).
 * The consent change is audited (opted_in / opted_out).
 */
final class ChangeClientConsent
{
    public function __construct(private readonly AuditRecorder $audit) {}

    public function handle(Client $client, ConsentState $state, User $actor, string $source = 'front_office'): ClientConsent
    {
        return DB::transaction(function () use ($client, $state, $actor, $source): ClientConsent {
            $consent = ClientConsent::query()->updateOrCreate(
                [
                    'client_id' => $client->id,
                    'channel' => ConsentChannel::Sms->value,
                ],
                [
                    'merchant_id' => $client->merchant_id,
                    'branch_id' => $client->branch_id,
                    'state' => $state->value,
                    'source' => $source,
                    'changed_at' => Carbon::now(),
                    'created_by' => $actor->id,
                ],
            );

            $this->audit->record(
                $state === ConsentState::OptedIn
                    ? AuditEvent::ClientConsentOptedIn
                    : AuditEvent::ClientConsentOptedOut,
                $actor,
                $client->merchant_id,
                $client->branch_id,
                $consent,
                ['client_id' => $client->ulid, 'channel' => ConsentChannel::Sms->value, 'state' => $state->value],
            );

            return $consent;
        });
    }
}

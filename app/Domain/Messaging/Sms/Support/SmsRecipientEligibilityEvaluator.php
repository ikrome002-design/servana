<?php

declare(strict_types=1);

namespace App\Domain\Messaging\Sms\Support;

use App\Domain\Clients\Enums\ClientStatus;
use App\Domain\Clients\Enums\ConsentChannel;
use App\Domain\Clients\Enums\ConsentState;
use App\Domain\Clients\Models\Client;
use App\Domain\Clients\Models\ClientConsent;
use App\Domain\Hr\Models\StaffProfile;
use App\Domain\Messaging\Sms\Enums\SmsConsentSnapshotStatus;
use App\Domain\Messaging\Sms\Enums\SmsRecipientExclusionReason;
use App\Domain\Messaging\Sms\ValueObjects\SmsEligibleRecipient;
use App\Domain\Messaging\Sms\ValueObjects\SmsExcludedRecipient;
use App\Domain\Messaging\Sms\ValueObjects\SmsRecipientEvaluation;

/**
 * Evaluates a selected list of client ULIDs against every Phase 21S eligibility gate
 * (Plan §64; ADR-010; Phase 21S).
 *
 * THE single evaluator, called by BOTH the preview endpoint and the confirm action. Running the
 * same code twice is what makes preview advisory and confirmation authoritative: a consent
 * withdrawal, an archival, or a session that stopped counting between the two produces a different
 * result at confirm, and confirm always wins.
 *
 * Gate order (each one fails CLOSED — an unknown state never becomes eligible):
 *   1. duplicate ULID in the selection            -> `duplicate_selection`
 *   2. ULID not visible to this membership        -> `unknown_client`   (uniform: absent /
 *      another merchant's / another branch's are indistinguishable, so the endpoint cannot be used
 *      as an existence oracle)
 *   3. no COMPLETED session performed by this staff profile -> `not_served`
 *   4. client archived                            -> `client_archived`
 *   5. consent opted out                          -> `consent_opted_out`
 *   6. no consent row at all                      -> `consent_missing`  (absence is NEVER consent)
 *
 * Order matters for the reason code, not for the verdict: ownership is checked before consent so a
 * Personnel user can never learn the consent state of a client they did not serve.
 */
final class SmsRecipientEligibilityEvaluator
{
    public function __construct(private readonly ServedClientSelector $servedClients) {}

    /**
     * @param  list<string>  $clientUlids  as submitted, in selection order
     */
    public function evaluate(StaffProfile $profile, array $clientUlids): SmsRecipientEvaluation
    {
        $eligible = [];
        $excluded = [];
        $seen = [];

        // Resolve the visible clients in ONE query (never N+1), through the tenant-scoped model so
        // merchant + branch isolation applies. Anything absent from this map is `unknown_client`.
        $unique = array_values(array_unique($clientUlids));

        /** @var array<string, Client> $clients */
        $clients = Client::query()
            ->whereIn('ulid', $unique)
            ->get()
            ->keyBy('ulid')
            ->all();

        $clientIds = array_map(static fn (Client $c): int => $c->id, array_values($clients));
        $consents = $this->consentStates($clientIds);
        // One grouped query for the whole selection — never a lookup per client (Plan §72).
        $sessions = $this->servedClients->evidencingSessionIds($profile, $clientIds);

        foreach ($clientUlids as $ulid) {
            if (isset($seen[$ulid])) {
                $excluded[] = new SmsExcludedRecipient(SmsRecipientExclusionReason::DuplicateSelection);

                continue;
            }

            $seen[$ulid] = true;
            $client = $clients[$ulid] ?? null;

            if ($client === null) {
                $excluded[] = new SmsExcludedRecipient(SmsRecipientExclusionReason::UnknownClient);

                continue;
            }

            // Ownership BEFORE consent: a Personnel user must never learn the consent state of a
            // client they did not personally serve.
            $sessionId = $sessions[$client->id] ?? null;

            if ($sessionId === null) {
                $excluded[] = new SmsExcludedRecipient(SmsRecipientExclusionReason::NotServed);

                continue;
            }

            $consent = SmsConsentSnapshotStatus::fromConsentState($consents[$client->id] ?? null);

            if ($client->status !== ClientStatus::Active) {
                $excluded[] = new SmsExcludedRecipient(
                    SmsRecipientExclusionReason::ClientArchived,
                    $client,
                    $sessionId,
                    // Recorded truthfully — an archived client may well still be opted in.
                    $consent,
                );

                continue;
            }

            if (! $consent->permitsDelivery()) {
                $reason = $consent->exclusionReason();
                // Non-null by construction: permitsDelivery() is false only for opted_out/missing.
                $excluded[] = new SmsExcludedRecipient(
                    $reason ?? SmsRecipientExclusionReason::ConsentMissing,
                    $client,
                    $sessionId,
                    $consent,
                );

                continue;
            }

            $eligible[] = new SmsEligibleRecipient($client, $sessionId, $consent);
        }

        return new SmsRecipientEvaluation($eligible, $excluded);
    }

    /**
     * Current SMS consent state per client id. A client with no row is simply absent from the map —
     * {@see SmsConsentSnapshotStatus::fromConsentState()} turns that into `missing`, never into
     * consent.
     *
     * @param  list<int>  $clientIds
     * @return array<int, ConsentState>
     */
    private function consentStates(array $clientIds): array
    {
        if ($clientIds === []) {
            return [];
        }

        $states = [];

        /** @var ClientConsent $consent */
        foreach (ClientConsent::query()
            ->whereIn('client_id', $clientIds)
            ->where('channel', ConsentChannel::Sms->value)
            ->get() as $consent) {
            $states[$consent->client_id] = $consent->state;
        }

        return $states;
    }
}

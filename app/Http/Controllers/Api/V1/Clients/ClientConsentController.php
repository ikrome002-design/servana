<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Clients;

use App\Domain\Clients\Actions\ChangeClientConsent;
use App\Domain\Clients\Enums\ConsentState;
use App\Domain\Clients\Models\Client;
use App\Http\Controllers\Controller;
use App\Http\Requests\Clients\ChangeConsentRequest;
use App\Http\Resources\ClientConsentResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

/**
 * Client SMS consent (Plan §35). Front Office records/changes the single current
 * SMS-consent state (`client.update` via ClientPolicy::manageConsent). NO SMS is
 * sent in Phase 15A (Phase 21S). The `{client}` binding resolves inside tenant/
 * branch scope (foreign 404). PUT is an idempotent state-set, so the response is a
 * stable 200 whether the consent row was created or updated.
 */
final class ClientConsentController extends Controller
{
    public function update(ChangeConsentRequest $request, Client $client, ChangeClientConsent $action): JsonResponse
    {
        $this->authorize('manageConsent', $client);

        $state = ConsentState::from((string) $request->validated()['state']);

        /** @var User $actor */
        $actor = $request->user();

        return ClientConsentResource::make($action->handle($client, $state, $actor))
            ->response()->setStatusCode(Response::HTTP_OK);
    }
}

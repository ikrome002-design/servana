<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Messaging;

use App\Domain\Clients\Models\Client;
use App\Domain\Hr\Models\StaffProfile;
use App\Domain\Messaging\Sms\Support\ServedClientSelector;
use App\Domain\Tenancy\TenantContext;
use App\Http\Api\ApiPagination;
use App\Http\Controllers\Controller;
use App\Http\Requests\Messaging\ServedClientsSmsIndexRequest;
use App\Http\Resources\ServedClientForSmsResource;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * The Personnel own-scope served-client list for SMS selection (Plan §64, §68; ADR-010; Phase 21S).
 *
 * A Personnel user sees ONLY clients they have PERSONALLY SERVED — at least one completed service
 * session performed by their own staff profile, inside the acting merchant and branch scope. The
 * definition lives in {@see ServedClientSelector} and is enforced server-side; the staff profile is
 * derived from the authenticated membership and is never accepted from the request.
 *
 * CONTACT PROTECTION (ADR-010): the collection returns the public ULID, the name and a MASKED phone
 * only ({@see ServedClientForSmsResource}). It paginates, it is rate-limited, its search matches the
 * NAME only, and there is no export, download, print or copy counterpart anywhere — this endpoint
 * is the closest thing Servana has to a contact list, and it is deliberately unusable as one.
 *
 * Gated by `personnel.my_served_clients.view`, whose matrix row is `allow_read` in billing
 * read-only: a merchant in grace can still SEE their served clients; only SENDING is blocked.
 */
final class PersonnelServedClientController extends Controller
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly ServedClientSelector $servedClients,
    ) {}

    public function index(ServedClientsSmsIndexRequest $request): AnonymousResourceCollection
    {
        abort_unless($this->context->can('personnel.my_served_clients.view'), 403);

        $profile = $this->ownStaffProfile();
        $filters = $request->validated();

        if ($profile === null) {
            // No staff profile ⇒ no served clients. An empty page, never an unscoped query.
            return ServedClientForSmsResource::collection(
                Client::query()->whereRaw('1 = 0')->paginate(ApiPagination::perPage($filters)),
            );
        }

        $query = $this->servedClients->availableForSms($profile, $filters['search'] ?? null);

        ApiPagination::applySort($query, $filters['sort'] ?? null, 'full_name');

        return ServedClientForSmsResource::collection(
            $query->paginate(ApiPagination::perPage($filters))->withQueryString(),
        );
    }

    /** The acting user's own staff profile — derived from the membership, never from the request. */
    private function ownStaffProfile(): ?StaffProfile
    {
        $merchantUser = $this->context->merchantUser();

        if ($merchantUser === null) {
            return null;
        }

        return StaffProfile::query()->where('merchant_user_id', $merchantUser->id)->first();
    }
}

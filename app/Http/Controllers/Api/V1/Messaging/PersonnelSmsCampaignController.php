<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Messaging;

use App\Domain\Hr\Models\StaffProfile;
use App\Domain\Messaging\Sms\Actions\CancelSmsCampaign;
use App\Domain\Messaging\Sms\Actions\ConfirmSmsCampaign;
use App\Domain\Messaging\Sms\Actions\CreateSmsCampaign;
use App\Domain\Messaging\Sms\Actions\PreviewSmsCampaign;
use App\Domain\Messaging\Sms\Actions\QueueSmsCampaign;
use App\Domain\Messaging\Sms\Models\PersonnelSmsCampaign;
use App\Domain\Messaging\Sms\Models\PersonnelSmsRecipient;
use App\Domain\Tenancy\TenantContext;
use App\Http\Api\ApiPagination;
use App\Http\Controllers\Controller;
use App\Http\Requests\Messaging\CancelPersonnelSmsCampaignRequest;
use App\Http\Requests\Messaging\ConfirmPersonnelSmsCampaignRequest;
use App\Http\Requests\Messaging\PersonnelSmsCampaignIndexRequest;
use App\Http\Requests\Messaging\PreviewPersonnelSmsCampaignRequest;
use App\Http\Requests\Messaging\StorePersonnelSmsCampaignRequest;
use App\Http\Resources\PersonnelSmsCampaignResource;
use App\Http\Resources\PersonnelSmsRecipientResource;
use App\Http\Resources\SmsCampaignPreviewResource;
use App\Models\User;
use App\Policies\PersonnelSmsCampaignPolicy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

/**
 * Personnel own-scope SMS campaigns (Plan §64; ADR-010; Phase 21S). Thin: validate → authorize →
 * action → Resource. No business rule lives here.
 *
 * OWN SCOPE IS DERIVED, NEVER SUPPLIED. Every method resolves the acting user's staff profile from
 * the authenticated membership; no route, request or filter accepts a staff identifier. Reads are
 * additionally constrained to the acting profile's own campaigns, and
 * {@see PersonnelSmsCampaignPolicy} re-checks ownership on every single-campaign
 * operation.
 *
 * CONTACT PROTECTION: no method on this controller returns a full phone number, and there is no
 * export, download, print or copy action — the recipients endpoint returns the masked form only.
 */
final class PersonnelSmsCampaignController extends Controller
{
    public function __construct(private readonly TenantContext $context) {}

    /** Advisory preview — creates nothing, sends nothing, bills nothing. */
    public function preview(PreviewPersonnelSmsCampaignRequest $request, PreviewSmsCampaign $preview): SmsCampaignPreviewResource
    {
        $this->authorize('create', PersonnelSmsCampaign::class);

        return new SmsCampaignPreviewResource($preview->handle(
            $this->requireOwnStaffProfile(),
            $request->clientUlids(),
            $request->messageBody(),
            $this->actor(),
        ));
    }

    /** Compose the draft + its immutable recipient snapshots. Still bills and sends nothing. */
    public function store(StorePersonnelSmsCampaignRequest $request, CreateSmsCampaign $create): JsonResponse
    {
        $this->authorize('create', PersonnelSmsCampaign::class);

        $campaign = $create->handle(
            $this->requireOwnStaffProfile(),
            $request->clientUlids(),
            $request->messageBody(),
            $this->actor(),
        );

        return (new PersonnelSmsCampaignResource($campaign))->response()->setStatusCode(201);
    }

    /**
     * The commitment point. Revalidates every recipient, snapshots consent, creates the single
     * billing entry, and queues delivery AFTER COMMIT — never inside the transaction.
     */
    public function confirm(
        ConfirmPersonnelSmsCampaignRequest $request,
        PersonnelSmsCampaign $campaign,
        ConfirmSmsCampaign $confirm,
        QueueSmsCampaign $queue,
    ): PersonnelSmsCampaignResource {
        $this->authorize('confirm', $campaign);

        $confirmed = $confirm->handle($campaign, $this->actor());

        // Dispatch strictly after the confirm transaction commits: a rolled-back confirmation can
        // never leave a queued send behind.
        DB::afterCommit(static fn () => $queue->handle($confirmed));

        return new PersonnelSmsCampaignResource($confirmed->refresh());
    }

    public function cancel(
        CancelPersonnelSmsCampaignRequest $request,
        PersonnelSmsCampaign $campaign,
        CancelSmsCampaign $cancel,
    ): PersonnelSmsCampaignResource {
        $this->authorize('cancel', $campaign);

        return new PersonnelSmsCampaignResource($cancel->handle($campaign, $this->actor()));
    }

    public function index(PersonnelSmsCampaignIndexRequest $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', PersonnelSmsCampaign::class);

        $profile = $this->ownStaffProfile();
        $filters = $request->validated();

        $query = PersonnelSmsCampaign::query()
            // Own scope, server-side: the acting staff profile only. No staff profile means no
            // campaigns at all — an impossible id, never an unscoped query.
            ->where('staff_profile_id', $profile === null ? 0 : $profile->id);

        if (isset($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        ApiPagination::applySort($query, $filters['sort'] ?? null, '-created_at');

        return PersonnelSmsCampaignResource::collection(
            $query->paginate(ApiPagination::perPage($filters))->withQueryString(),
        );
    }

    public function show(PersonnelSmsCampaign $campaign): PersonnelSmsCampaignResource
    {
        $this->authorize('view', $campaign);

        return new PersonnelSmsCampaignResource($campaign);
    }

    /** Recipient statuses — masked contact only, never a phone list. */
    public function recipients(PersonnelSmsCampaignIndexRequest $request, PersonnelSmsCampaign $campaign): AnonymousResourceCollection
    {
        $this->authorize('view', $campaign);

        $query = PersonnelSmsRecipient::query()
            ->where('campaign_id', $campaign->id)
            ->with('client');

        ApiPagination::applySort($query, null, 'id');

        return PersonnelSmsRecipientResource::collection(
            $query->paginate(ApiPagination::perPage($request->validated()))->withQueryString(),
        );
    }

    private function actor(): User
    {
        /** @var User $user */
        $user = request()->user();

        return $user;
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

    /** A Personnel user without a staff profile has no own scope at all — 403, never unscoped. */
    private function requireOwnStaffProfile(): StaffProfile
    {
        $profile = $this->ownStaffProfile();

        abort_if($profile === null, 403);

        return $profile;
    }
}

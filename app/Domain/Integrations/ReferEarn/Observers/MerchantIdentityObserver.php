<?php

declare(strict_types=1);

namespace App\Domain\Integrations\ReferEarn\Observers;

use App\Domain\Integrations\ReferEarn\Actions\EnqueueProductEvent;
use App\Domain\Integrations\ReferEarn\Enums\ReOutboundEventType;
use App\Domain\Integrations\ReferEarn\Support\MerchantEventPayloadBuilder;
use App\Domain\Merchants\Models\Merchant;
use App\Domain\Merchants\Models\MerchantProfile;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Emits `merchant.identity_snapshot_changed` when a merchant's legal/business identity changes
 * (Plan §58B.1, §58B.2; Phase 21R-A).
 *
 * **Why an observer and not a call site.** Inspecting the as-built code first (Plan §81 rule 22)
 * showed there is currently NO merchant identity-update route or action: `merchants.name` and the
 * `merchant_profiles` identity columns are written by `RegisterMerchant` (create) and
 * `CompleteFirstTimeSetup` (update), and nothing else. There is therefore no single seam to wire.
 * An observer covers every present and future writer without inventing a route that Phase 21R-A has
 * no mandate to add, and without scattering emission calls through unrelated actions.
 *
 * It fires on `updated` only — a merchant's first identity is reported by
 * `merchant.registration_started`, not as a change.
 *
 * **Known limitation, stated rather than hidden:** Eloquent observers do not fire for query-builder
 * mass updates (`Merchant::query()->update([...])`). Every as-built writer of these columns uses a
 * model save, so nothing is missed today, and a future bulk-rename path would have to emit
 * explicitly. This is recorded as a residual risk in `docs/proof/phase-21r-a.md`.
 *
 * Payload carries the snapshot HASH and a changed-field COUNT, never a field name or value
 * (Plan §58B.1: "snapshot hash, not raw documents").
 */
final class MerchantIdentityObserver
{
    public function __construct(
        private readonly EnqueueProductEvent $enqueue,
        private readonly MerchantEventPayloadBuilder $payloads,
    ) {}

    public function updated(Model $model): void
    {
        $changedIdentityFields = $this->changedIdentityFields($model);

        if ($changedIdentityFields === 0) {
            return;
        }

        $merchant = $this->resolveMerchant($model);

        if ($merchant === null) {
            return;
        }

        // The emission-scope gate is inside EnqueueProductEvent, but checking here too avoids
        // opening a transaction for the overwhelmingly common unreferred-merchant case.
        if (! $this->enqueue->mayEmitFor($merchant)) {
            return;
        }

        // `updated` fires inside the writer's transaction when there is one, and this nested call
        // is then a savepoint — so the event stays atomic with the identity change. When the writer
        // used no transaction the row is already committed, and this opens its own; that is the
        // honest best available guarantee for a write that was not itself transactional.
        DB::transaction(function () use ($merchant, $changedIdentityFields): void {
            // Refresh so a profile-side change is reflected in the hash the merchant computes.
            $fresh = Merchant::query()->whereKey($merchant->getKey())->first() ?? $merchant;

            $this->enqueue->handle(
                ReOutboundEventType::MerchantIdentitySnapshotChanged,
                $fresh,
                [
                    'identity_snapshot_sha256' => $this->payloads->identitySnapshotHash($fresh),
                    'changed_field_count' => $changedIdentityFields,
                ],
            );
        });
    }

    private function resolveMerchant(Model $model): ?Merchant
    {
        if ($model instanceof Merchant) {
            return $model;
        }

        $merchantId = $model->getAttribute('merchant_id');

        return is_int($merchantId)
            ? Merchant::query()->whereKey($merchantId)->first()
            : null;
    }

    /** How many of THIS model's identity fields actually changed in the save that just happened. */
    private function changedIdentityFields(Model $model): int
    {
        $fields = MerchantEventPayloadBuilder::IDENTITY_FIELDS[$model::class] ?? [];

        if ($fields === []) {
            return 0;
        }

        return count(array_intersect($fields, array_keys($model->getChanges())));
    }

    /** @return list<class-string<Model>> models this observer must be registered on */
    public static function observedModels(): array
    {
        return [Merchant::class, MerchantProfile::class];
    }
}

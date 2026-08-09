<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Platform;

use App\Domain\Billing\Models\BillingEscalationEvent;
use App\Domain\Billing\Models\MerchantSubscription;
use App\Domain\Billing\Models\SubscriptionInvoice;
use App\Domain\Billing\Queries\PlatformSubscriptionOperationsProjection;
use App\Domain\Merchants\Models\Merchant;
use App\Enums\Currency;
use App\Http\Controllers\Controller;
use App\Http\Requests\Platform\PlatformSubscriptionInvoiceQueryRequest;
use App\Http\Requests\Platform\PlatformSubscriptionQueryRequest;
use App\Http\Resources\PlatformSubscriptionInvoiceResource;
use App\Http\Resources\PlatformSubscriptionResource;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Platform subscription operations (COR-UI08-001 §10; navigation map §5.4.13, /billing/subscriptions).
 *
 * MONITORING ONLY. Every action here is a GET. There is deliberately no mutation endpoint of any
 * kind: no subscription or invoice edit, no manual payment recording, no credit write, no provider
 * status query. Recovery from an overdue or suspended state is a merchant-side payment outcome
 * whose money movement is Wallet truth (ADR-012), not a platform button.
 *
 * Authorized by `platform.merchant.view` — the existing platform merchant-governance read key. A
 * merchant-tenant key such as `merchant.subscription.view` grants nothing here, which
 * `Ui08SubscriptionOperationsAuthorizationTest` proves directly.
 */
final class PlatformSubscriptionOperationsController extends Controller
{
    public function __construct(private readonly PlatformSubscriptionOperationsProjection $projection) {}

    public function summary(): JsonResponse
    {
        $this->authorize('viewGovernance', Merchant::class);

        $summary = $this->projection->summary();

        return response()->json([
            'data' => $summary,
            'meta' => [
                // A number on a governance screen is not evidence unless it says what it counts.
                'definitions' => [
                    'subscriptions_by_status' => 'Count of merchant_subscriptions rows per record status, at the instant shown.',
                    'invoices_by_status' => 'Count of subscription_invoices rows per invoice status, at the instant shown.',
                    'cohorts' => 'Lifecycle groupings derived from the same record statuses; a subscription appears in exactly one.',
                    'funnel' => 'Trial-to-active progression derived from current record status only — it is not a historical cohort study.',
                    'open_invoice_balance_minor' => 'Sum of balance_minor over issued, pending_payment, partially_paid and overdue invoices, in integer minor units.',
                ],
                'time_range' => 'Point-in-time as of the as_of timestamp; no figure is a rolling window.',
                'authorization_authority' => 'merchants.billing_status',
            ],
        ]);
    }

    public function subscriptions(PlatformSubscriptionQueryRequest $request): AnonymousResourceCollection
    {
        $this->authorize('viewGovernance', Merchant::class);

        $filters = $request->filters();
        $page = $this->projection->subscriptions($filters, (int) ($request->validated('per_page') ?? 25));

        return PlatformSubscriptionResource::collection($page);
    }

    public function showSubscription(MerchantSubscription $merchantSubscription): PlatformSubscriptionResource
    {
        $this->authorize('viewGovernance', Merchant::class);

        return PlatformSubscriptionResource::make(
            $merchantSubscription->load(['merchant', 'plan', 'price', 'scheduledPlanChanges']),
        );
    }

    public function invoices(PlatformSubscriptionInvoiceQueryRequest $request): AnonymousResourceCollection
    {
        $this->authorize('viewGovernance', Merchant::class);

        $page = $this->projection->invoices(
            $request->filters(),
            (int) ($request->validated('per_page') ?? 25),
        );

        return PlatformSubscriptionInvoiceResource::collection($page);
    }

    public function showInvoice(SubscriptionInvoice $subscriptionInvoice): PlatformSubscriptionInvoiceResource
    {
        $this->authorize('viewGovernance', Merchant::class);

        return PlatformSubscriptionInvoiceResource::make(
            $subscriptionInvoice->load(['merchant', 'plan']),
        );
    }

    /**
     * Billing credits are credit LINES on existing invoices. Servana holds no separate credit
     * ledger, and this endpoint deliberately does not invent one.
     */
    public function billingCredits(Request $request): JsonResponse
    {
        $this->authorize('viewGovernance', Merchant::class);

        $page = $this->projection->billingCredits($this->merchantIdFromUlid($request), 25);

        $data = [];
        foreach ($page->items() as $item) {
            $invoice = $item->invoice;
            $currency = $invoice === null ? Currency::KES : Currency::from($invoice->currency);

            $data[] = [
                'id' => $item->ulid,
                'invoice_id' => $invoice?->ulid,
                'merchant' => [
                    'id' => $invoice?->merchant?->ulid,
                    'name' => $invoice?->merchant?->name,
                ],
                'description' => $item->description,
                'type' => $item->type->value,
                'amount' => Money::ofMinor($item->amount_minor, $currency)->toArray(),
                'created_at' => $item->created_at?->toIso8601String(),
            ];
        }

        return response()->json([
            'data' => $data,
            'meta' => $this->pageMeta($page->currentPage(), $page->lastPage(), $page->perPage(), $page->total())
                + ['source' => 'subscription_invoice_items with a negative amount; Servana holds no separate credit ledger'],
        ]);
    }

    public function escalations(Request $request): JsonResponse
    {
        $this->authorize('viewGovernance', Merchant::class);

        $page = $this->projection->escalations($this->merchantIdFromUlid($request), 25);

        $data = [];
        foreach ($page->items() as $event) {
            /** @var BillingEscalationEvent $event */
            $data[] = [
                'id' => $event->ulid,
                'merchant' => [
                    'id' => $event->merchant?->ulid,
                    'name' => $event->merchant?->name,
                ],
                'event_type' => $event->event_type->value,
                'from_billing_status' => $event->from_billing_status,
                'to_billing_status' => $event->to_billing_status,
                'reason' => $event->reason,
                // Absolute timestamps only — a governance record never says "3 days ago".
                'period_boundary' => CarbonImmutable::parse((string) $event->period_boundary)->toIso8601String(),
                'created_at' => $event->created_at?->toIso8601String(),
            ];
        }

        return response()->json([
            'data' => $data,
            'meta' => $this->pageMeta($page->currentPage(), $page->lastPage(), $page->perPage(), $page->total()),
        ]);
    }

    /**
     * An unknown merchant ULID narrows to no rows rather than erroring, so the filter cannot be
     * used to probe which merchant identifiers exist.
     */
    private function merchantIdFromUlid(Request $request): ?int
    {
        $ulid = $request->query('merchant');

        if (! is_string($ulid) || $ulid === '') {
            return null;
        }

        $id = Merchant::query()->where('ulid', $ulid)->value('id');

        return $id === null ? -1 : (int) $id;
    }

    /** @return array{current_page:int,last_page:int,per_page:int,total:int} */
    private function pageMeta(int $currentPage, int $lastPage, int $perPage, int $total): array
    {
        return [
            'current_page' => $currentPage,
            'last_page' => $lastPage,
            'per_page' => $perPage,
            'total' => $total,
        ];
    }
}

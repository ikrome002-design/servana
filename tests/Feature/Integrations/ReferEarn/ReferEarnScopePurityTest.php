<?php

declare(strict_types=1);

use App\Domain\Integrations\ReferEarn\Enums\ReOutboundEventType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class)->group('referearn', 'phase21ra', 'phase21ra-scope');

/*
 | Scope purity for Phase 21R-A (Plan §2.2 ownership matrix, §80 phase boundaries, ADR-012/013).
 |
 | Building a partner-owned capability in Servana is a defect EVEN IF IT WORKS (Plan §0 rule 12), and
 | building a later phase early is the same defect. These assertions are the standing guard: they
 | fail the moment 21R-B, Wallet runtime, or R&E reward logic starts appearing in this repository
 | under the cover of "the integration".
 */

it('implements only the five Phase 21R-A event types', function (): void {
    expect(ReOutboundEventType::values())->toBe([
        'merchant.registration_started',
        'merchant.admin_created',
        'merchant.setup_completed',
        'merchant.status_changed',
        'merchant.identity_snapshot_changed',
    ]);
});

it('implements no Phase 21R-B subscription or activity event', function (): void {
    $forbidden = [
        'subscription.invoice_issued', 'subscription.payment_received', 'subscription.payment_cleared',
        'subscription.payment_reversed', 'subscription.refund_issued', 'subscription.chargeback_recorded',
        'subscription.plan_changed', 'subscription.suspended',
        'activity.qualification_decided', 'activity.qualification_corrected',
        'merchant.product_tenant_closed',
    ];

    foreach ($forbidden as $type) {
        expect(ReOutboundEventType::tryFrom($type))->toBeNull("{$type} is Phase 21R-B, not 21R-A");
    }
});

it('creates no Phase 21R-B table', function (): void {
    foreach (['re_activity_rule_versions', 're_qualification_periods', 're_qualification_decisions', 're_inbound_requests'] as $table) {
        expect(Schema::hasTable($table))->toBeFalse("{$table} is Phase 21R-B");
    }
});

it('creates no R&E platform-owned table', function (): void {
    // ADR-013: referrer accounts, campaigns, reward rules, reward calculation, the reward ledger,
    // referrer payouts and reward statements belong to Citrus Refer & Earn, never to Servana.
    foreach ([
        'referrer_accounts', 'referrers', 'referral_campaigns', 'campaigns', 'reward_rules',
        'reward_ledgers', 'rewards', 'referrer_payouts', 'referrer_statements', 'referral_codes',
    ] as $table) {
        expect(Schema::hasTable($table))->toBeFalse("{$table} is owned by Citrus Refer & Earn");
    }
});

it('creates no Wallet / Phase 20D-W table (Gate W is closed)', function (): void {
    foreach ([
        'subscription_payments', 'subscription_payment_attempts', 'subscription_payment_reversals',
        'wallet_webhook_inbox', 'billing_reconciliation_exceptions', 'merchant_wallet_accounts',
        'merchant_billing_credits',
    ] as $table) {
        expect(Schema::hasTable($table))->toBeFalse("{$table} is Phase 20D-W and Gate W is closed");
    }
});

it('adds no R&E domain class outside the Phase 21R-A surface', function (): void {
    // RECURSIVE on purpose: PHP's glob() does not expand `**` across nested directories, so a glob
    // would silently miss `Clients/Dto/*` and the guard would pass while blind.
    $files = collect(referEarnSourceFiles())
        ->map(fn (string $path): string => basename($path, '.php'))
        ->sort()
        ->values()
        ->all();

    // An explicit inventory: a new class here is either a Phase 21R-A file this list should name,
    // or scope creep this test should catch.
    expect($files)->toBe([
        'AttributionConfirmation',
        'CanonicalJson',
        'CaptureReferralSnapshot',
        'CitrusEventSigner',
        'ConfirmAttribution',
        'ConfirmAttributionJob',
        'DeliverProductEvent',
        'DeliverReOutboxJob',
        'DeliveryResponseRedactor',
        'EnqueueProductEvent',
        'EventDeliveryResult',
        'FakeReferEarnClient',
        'HttpReferEarnClient',
        'LandingMetadataAllowlist',
        'MerchantEventPayloadBuilder',
        'MerchantIdentityObserver',
        'MerchantStatusReasonCategory',
        'ReDeliveryResponseClass',
        'ReDeliveryStatus',
        'ReEventDelivery',
        'ReOutboundEvent',
        'ReOutboundEventType',
        'ReferEarnClientInterface',
        'ReferEarnSigningException',
        'ReferralCaptureChannel',
        'ReferralCaptureData',
        'ReferralCodeNormalizer',
        'ReferralCodeValidation',
        'ReferralSnapshot',
        'ReferralSnapshotStateException',
        'ReferralSnapshotStatus',
        'TransitionReferralSnapshot',
        'ValidateReferralCode',
        'ValidateReferralCodeJob',
    ]);
});

it('introduces no qualification, reward or reconciliation symbol', function (): void {
    $sources = collect(referEarnSourceFiles())
        ->map(fn (string $path): string => (string) file_get_contents($path))
        ->implode("\n");

    foreach (['Qualification', 'RewardLedger', 'ReferrerAccount', 'Campaign', 'Payout', 'Reconciliation'] as $symbol) {
        expect($sources)->not->toContain($symbol.' ', "{$symbol} is not Phase 21R-A work");
    }
});

/** @return list<string> every PHP file under the ReferEarn bounded context, recursively. */
function referEarnSourceFiles(): array
{
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(app_path('Domain/Integrations/ReferEarn'), FilesystemIterator::SKIP_DOTS)
    );

    $files = [];

    foreach ($iterator as $file) {
        if ($file instanceof SplFileInfo && $file->getExtension() === 'php') {
            $files[] = $file->getPathname();
        }
    }

    return $files;
}

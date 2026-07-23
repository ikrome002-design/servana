<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Audit\Contracts\AuditRecorder;
use App\Domain\Audit\Models\AuditExport;
use App\Domain\Audit\Models\AuditFlaggedEvent;
use App\Domain\Audit\Models\AuditLog;
use App\Domain\Audit\Services\DatabaseAuditRecorder;
use App\Domain\Billing\Contracts\PlanContextResolver;
use App\Domain\Billing\Models\FreePeriodOffer;
use App\Domain\Billing\Models\MerchantSubscription;
use App\Domain\Billing\Models\PlatformBillingSettings;
use App\Domain\Billing\Models\PlatformFeeConfiguration;
use App\Domain\Billing\Models\PlatformFeeDispute;
use App\Domain\Billing\Models\PlatformFeeLedgerEntry;
use App\Domain\Billing\Models\PreferredPersonnelFeeRule;
use App\Domain\Billing\Models\PromotionalDiscount;
use App\Domain\Billing\Models\SubscriptionInvoice;
use App\Domain\Billing\Models\SubscriptionPlan;
use App\Domain\Billing\Models\SubscriptionPlanPrice;
use App\Domain\Billing\Services\SubscriptionPlanContextResolver;
use App\Domain\Branches\Models\BranchCashUp;
use App\Domain\Branches\Models\BranchDayRecord;
use App\Domain\Branches\Models\BranchOperatingHour;
use App\Domain\Branches\Models\MerchantBranch;
use App\Domain\Catalogue\Models\Service;
use App\Domain\Catalogue\Models\ServiceCategory;
use App\Domain\Catalogue\Models\ServicePersonnelEligibility;
use App\Domain\Clients\Models\Client;
use App\Domain\Compensation\Models\CommissionLedgerEntry;
use App\Domain\Compensation\Models\CommissionRule;
use App\Domain\Compensation\Models\CompensationAdjustment;
use App\Domain\Compensation\Models\EarningsQuery;
use App\Domain\Compensation\Models\PersonnelCompensationPlan;
use App\Domain\Compensation\Models\PersonnelPayoutRun;
use App\Domain\Compensation\Models\SalaryLedgerEntry;
use App\Domain\Files\Contracts\FileScanner;
use App\Domain\Files\Services\ClamAvScanner;
use App\Domain\Files\Services\ImageSanitizer;
use App\Domain\FinanceOps\Contracts\PeriodLockRepository;
use App\Domain\FinanceOps\Models\FinanceDispute;
use App\Domain\FinanceOps\Models\FinanceExport;
use App\Domain\FinanceOps\Models\FinancialPeriodLock;
use App\Domain\FinanceOps\Support\DatabasePeriodLockRepository;
use App\Domain\Hr\Models\StaffInvitation;
use App\Domain\Hr\Models\StaffProfile;
use App\Domain\Integrations\ReferEarn\Clients\FakeReferEarnClient;
use App\Domain\Integrations\ReferEarn\Clients\HttpReferEarnClient;
use App\Domain\Integrations\ReferEarn\Clients\ReferEarnClientInterface;
use App\Domain\Integrations\ReferEarn\Observers\MerchantIdentityObserver;
use App\Domain\Invoicing\Contracts\PreferredPersonnelFeeResolver;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Invoicing\Services\RuleBasedPreferredPersonnelFeeResolver;
use App\Domain\Merchants\Models\Merchant;
use App\Domain\Merchants\Models\MerchantUser;
use App\Domain\Messaging\Sms\Clients\FakeSmsProviderClient;
use App\Domain\Messaging\Sms\Clients\HttpSmsProviderClient;
use App\Domain\Messaging\Sms\Clients\SmsProviderClientInterface;
use App\Domain\Messaging\Sms\Models\PersonnelSmsCampaign;
use App\Domain\Payments\Models\PaymentRecord;
use App\Domain\Payments\Models\PaymentRecordingGroup;
use App\Domain\Payments\Models\PaymentReferenceCheck;
use App\Domain\Receipts\Models\Receipt;
use App\Domain\Refunds\Models\Refund;
use App\Domain\Scheduling\Models\Appointment;
use App\Domain\Scheduling\Models\QueueEntry;
use App\Domain\Scheduling\Models\ServiceSession;
use App\Domain\Tenancy\TenantContext;
use App\Policies\AppointmentPolicy;
use App\Policies\AuditExportPolicy;
use App\Policies\AuditFlaggedEventPolicy;
use App\Policies\AuditLogPolicy;
use App\Policies\BranchDayRecordPolicy;
use App\Policies\BranchOperatingHourPolicy;
use App\Policies\CashUpPolicy;
use App\Policies\ClientPolicy;
use App\Policies\CommissionLedgerEntryPolicy;
use App\Policies\CommissionRulePolicy;
use App\Policies\CompensationAdjustmentPolicy;
use App\Policies\EarningsQueryPolicy;
use App\Policies\FinanceDisputePolicy;
use App\Policies\FinanceExportPolicy;
use App\Policies\FinancialPeriodLockPolicy;
use App\Policies\FreePeriodOfferPolicy;
use App\Policies\InvoicePolicy;
use App\Policies\MerchantBranchPolicy;
use App\Policies\MerchantPolicy;
use App\Policies\MerchantSubscriptionPolicy;
use App\Policies\MerchantUserPolicy;
use App\Policies\PaymentRecordingGroupPolicy;
use App\Policies\PersonnelCompensationPlanPolicy;
use App\Policies\PersonnelPayoutRunPolicy;
use App\Policies\PersonnelSmsCampaignPolicy;
use App\Policies\PlatformBillingSettingsPolicy;
use App\Policies\PlatformFeeConfigurationPolicy;
use App\Policies\PlatformFeeDisputePolicy;
use App\Policies\PlatformFeeLedgerEntryPolicy;
use App\Policies\PreferredPersonnelFeeRulePolicy;
use App\Policies\PromotionalDiscountPolicy;
use App\Policies\QueueEntryPolicy;
use App\Policies\ReceiptPolicy;
use App\Policies\RefundPolicy;
use App\Policies\SalaryLedgerEntryPolicy;
use App\Policies\ServiceCategoryPolicy;
use App\Policies\ServicePersonnelEligibilityPolicy;
use App\Policies\ServicePolicy;
use App\Policies\ServiceSessionPolicy;
use App\Policies\StaffInvitationPolicy;
use App\Policies\StaffProfilePolicy;
use App\Policies\SubscriptionInvoicePolicy;
use App\Policies\SubscriptionPlanPolicy;
use App\Policies\SubscriptionPlanPricePolicy;
use App\Support\CorrelationId;
use Dedoc\Scramble\Scramble;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Model → policy map (Plan §10.4).
     *
     * @var array<class-string, class-string>
     */
    private const POLICIES = [
        Merchant::class => MerchantPolicy::class,
        MerchantBranch::class => MerchantBranchPolicy::class,
        MerchantUser::class => MerchantUserPolicy::class,
        StaffInvitation::class => StaffInvitationPolicy::class,
        StaffProfile::class => StaffProfilePolicy::class,
        BranchOperatingHour::class => BranchOperatingHourPolicy::class,
        BranchDayRecord::class => BranchDayRecordPolicy::class,
        AuditLog::class => AuditLogPolicy::class,
        AuditFlaggedEvent::class => AuditFlaggedEventPolicy::class,
        // Phase 19 — branch-scoped, reason-gated, masked audit exports (ADR-010).
        AuditExport::class => AuditExportPolicy::class,
        // Phase 15A — catalogue & clients.
        Service::class => ServicePolicy::class,
        ServiceCategory::class => ServiceCategoryPolicy::class,
        ServicePersonnelEligibility::class => ServicePersonnelEligibilityPolicy::class,
        Client::class => ClientPolicy::class,
        // Phase 16A — appointments.
        Appointment::class => AppointmentPolicy::class,
        // Phase 16B — walk-ins & queues.
        QueueEntry::class => QueueEntryPolicy::class,
        // Phase 16C — service sessions.
        ServiceSession::class => ServiceSessionPolicy::class,
        // Phase 21S — personnel bulk SMS (strictly own scope; ADR-010).
        PersonnelSmsCampaign::class => PersonnelSmsCampaignPolicy::class,
        // Phase 17 — invoicing.
        Invoice::class => InvoicePolicy::class,
        // Phase 18A — merchant-client payment recording (group + reference-check
        // override map to one payment policy).
        PaymentRecordingGroup::class => PaymentRecordingGroupPolicy::class,
        PaymentReferenceCheck::class => PaymentRecordingGroupPolicy::class,
        // Phase 18B — component reference correction authorizes against the same policy.
        PaymentRecord::class => PaymentRecordingGroupPolicy::class,
        // Phase 18B — receipt read / reissue / authorized download.
        Receipt::class => ReceiptPolicy::class,
        // Phase 18B — external refund workflow.
        Refund::class => RefundPolicy::class,
        // Phase 18B — finance disputes.
        FinanceDispute::class => FinanceDisputePolicy::class,
        // Phase 18B — branch cash-up + day-close reconciliation.
        BranchCashUp::class => CashUpPolicy::class,
        // Phase 18B — financial period locks + controlled reopen.
        FinancialPeriodLock::class => FinancialPeriodLockPolicy::class,
        // Phase 18B — scoped, masked finance exports.
        FinanceExport::class => FinanceExportPolicy::class,
        // Phase 20A — platform billing catalogue governance (Super-Admin platform scope).
        PlatformBillingSettings::class => PlatformBillingSettingsPolicy::class,
        SubscriptionPlan::class => SubscriptionPlanPolicy::class,
        SubscriptionPlanPrice::class => SubscriptionPlanPricePolicy::class,
        MerchantSubscription::class => MerchantSubscriptionPolicy::class,
        SubscriptionInvoice::class => SubscriptionInvoicePolicy::class,
        PreferredPersonnelFeeRule::class => PreferredPersonnelFeeRulePolicy::class,
        // Phase 20C — platform-governed promotions & free-period offers (Super-Admin platform scope).
        PromotionalDiscount::class => PromotionalDiscountPolicy::class,
        FreePeriodOffer::class => FreePeriodOfferPolicy::class,
        // Phase 20E — percentage platform-fee: configuration (Super-Admin platform scope), merchant-scoped
        // masked ledger read, and the dispute workflow.
        PlatformFeeConfiguration::class => PlatformFeeConfigurationPolicy::class,
        PlatformFeeLedgerEntry::class => PlatformFeeLedgerEntryPolicy::class,
        PlatformFeeDispute::class => PlatformFeeDisputePolicy::class,
        // Phase 20F — HR compensation configuration (branch-scoped; HR-only by Plan §10.2).
        PersonnelCompensationPlan::class => PersonnelCompensationPlanPolicy::class,
        CommissionRule::class => CommissionRulePolicy::class,
        // Phase 20G — Finance compensation-liability read + manual adjustment (merchant scope).
        CommissionLedgerEntry::class => CommissionLedgerEntryPolicy::class,
        SalaryLedgerEntry::class => SalaryLedgerEntryPolicy::class,
        CompensationAdjustment::class => CompensationAdjustmentPolicy::class,
        // Phase 20H — personnel payout runs (HR/Finance/Merchant-Admin split) + earnings queries
        // (personnel own-scope create/read; Finance respond).
        PersonnelPayoutRun::class => PersonnelPayoutRunPolicy::class,
        EarningsQuery::class => EarningsQueryPolicy::class,
    ];

    public function register(): void
    {
        // Shared per-request correlation id (middleware sets it; logging and the
        // error renderer read it).
        $this->app->singleton(CorrelationId::class);

        // Per-request tenant context (Plan §8.1). `scoped` so it is a singleton
        // within one request and reset between requests; ResolveTenantContext
        // populates it after auth.
        $this->app->scoped(TenantContext::class);

        // Audit trail (Plan §22.2). Table-backed minimal recorder introduced in
        // Phase 8; full §5.18 coverage matures in Phase 19.
        $this->app->bind(AuditRecorder::class, DatabaseAuditRecorder::class);

        // Period-lock enforcement (Plan §46; ADR-0007 Decision 2; Gate F, Phase 18B).
        // Phase 18B swaps the Phase 17 always-open stub for the database-backed
        // repository reading `financial_period_locks` — activating the
        // `423 financial_period_locked` contract everywhere with NO change to the
        // financial actions or the FinancialPeriodGuard.
        $this->app->bind(PeriodLockRepository::class, DatabasePeriodLockRepository::class);

        // Preferred-personnel-fee resolution at invoice finalization (Gate D). Phase 20A
        // ships `preferred_personnel_fee_rules` and replaces the legacy fixed
        // `services.preferred_personnel_fee_minor` seam with the rule-backed resolver
        // (LegacyPreferredPersonnelFeeResolver is retained for reference/history only).
        // Finalization semantics are unchanged; already-finalized invoices are never
        // recalculated. The prospective cutover is DATE '2026-07-10' (backfill migration).
        $this->app->bind(PreferredPersonnelFeeResolver::class, RuleBasedPreferredPersonnelFeeResolver::class);

        // Merchant→plan binding for the Plan §20 entitlement gate. Phase 20A shipped the interface
        // plus UnboundPlanContextResolver (always null) because merchant_subscriptions was Phase
        // 20B; 20B shipped the table but never replaced the binding, so no entitlement could ever
        // resolve. Phase 21S is the first phase with an entitlement-gated permission
        // (`personnel.my_sms.send`, entitlement_key `sms`), so it binds the concrete resolver.
        // UnboundPlanContextResolver is retained for reference/history only.
        $this->app->bind(PlanContextResolver::class, SubscriptionPlanContextResolver::class);

        // SMS transport (Phase 21S; Plan §64, §17.1, §81 rule 21; REM-SMS-002). The HTTP client is
        // bound ONLY when the integration is enabled AND every credential is configured — and never
        // in `testing`, whatever the environment says. Anything less binds the deterministic fake,
        // so a partly configured environment can never half-send and CI physically cannot reach a
        // live provider.
        $this->app->singleton(
            SmsProviderClientInterface::class,
            fn ($app): SmsProviderClientInterface => $this->smsIsDeliverable()
                ? $app->make(HttpSmsProviderClient::class)
                : $app->make(FakeSmsProviderClient::class),
        );

        // The fake is a singleton so a test can script outcomes on the same instance the domain
        // resolves, and assert afterwards on what Servana would have sent (digests only).
        $this->app->singleton(FakeSmsProviderClient::class);

        // Must run in register() — before dedoc/scramble's provider boots and
        // registers its default docs routes (Phase 10).
        $this->configureOpenApiGenerator();

        // File malware scanner (Phase 10F): production ClamAV INSTREAM adapter.
        // Tests bind a deterministic fake; the real EICAR test uses this adapter.
        $this->app->bind(
            FileScanner::class,
            fn (): ClamAvScanner => ClamAvScanner::fromConfig(),
        );

        // Image re-encoder (Phase 10F) — config-driven limits, not autowirable.
        $this->app->bind(
            ImageSanitizer::class,
            fn (): ImageSanitizer => ImageSanitizer::fromConfig(),
        );

        // Citrus R&E transport (Phase 21R-A; Plan §17.1, §80 entry-criteria fallback, §81 rule 21).
        // The HTTP client is bound ONLY when the integration is enabled AND every piece of the
        // signing contract is configured. Anything less binds the deterministic fake, so a partly
        // configured environment can never half-deliver, and CI — which configures none of it —
        // physically cannot reach a live partner.
        $this->app->singleton(
            ReferEarnClientInterface::class,
            fn ($app): ReferEarnClientInterface => $this->referEarnIsDeliverable()
                ? $app->make(HttpReferEarnClient::class)
                : $app->make(FakeReferEarnClient::class),
        );

        // The fake is a singleton so a test can script outcomes on the same instance the domain
        // resolves, and assert afterwards on what Servana would have sent.
        $this->app->singleton(FakeReferEarnClient::class);
    }

    /**
     * Every piece of the SMS provider contract present? Missing anything ⇒ fail closed to the fake.
     * `testing` short-circuits unconditionally so no test can reach a live provider even if an
     * environment file configures one (Plan §81 rule 21).
     */
    private function smsIsDeliverable(): bool
    {
        if ($this->app->environment('testing')) {
            return false;
        }

        if (config('sms.enabled') !== true) {
            return false;
        }

        foreach (['sms.base_url', 'sms.api_key', 'sms.sender_id', 'sms.contract_version'] as $key) {
            $value = config($key);

            if (! is_string($value) || trim($value) === '') {
                return false;
            }
        }

        return true;
    }

    /** Every piece of the R&E delivery contract present? Missing anything ⇒ fail closed to the fake. */
    private function referEarnIsDeliverable(): bool
    {
        if (config('refer-earn.enabled') !== true) {
            return false;
        }

        foreach (['refer-earn.base_url', 'refer-earn.signing.algorithm', 'refer-earn.signing.key_id', 'refer-earn.signing.secret'] as $key) {
            $value = config($key);

            if (! is_string($value) || trim($value) === '') {
                return false;
            }
        }

        return true;
    }

    public function boot(): void
    {
        $this->registerRateLimiters();
        $this->registerPolicies();

        // Phase 21R-A (Plan §58B.1). There is no merchant identity-update route as-built, so the
        // identity-change event is emitted by observing the identity columns themselves — see
        // MerchantIdentityObserver for the inspection that led to that choice.
        foreach (MerchantIdentityObserver::observedModels() as $model) {
            $model::observe(MerchantIdentityObserver::class);
        }
    }

    /**
     * Configure the maintained dedoc/scramble generator (ADR / Phase 10).
     *
     * The generator only ever analyses the current production `/api/v1` surface —
     * the test-only harness routes (registered under APP_ENV=testing) are excluded
     * at the source, so the document is identical across environments and Scramble
     * never analyses a harness closure. The default docs UI routes are not
     * registered: Servana ships the committed `docs/api/openapi.json` artifact, not
     * a live docs endpoint.
     */
    private function configureOpenApiGenerator(): void
    {
        Scramble::ignoreDefaultRoutes();

        Scramble::routes(function (Route $route): bool {
            $uri = $route->uri();
            $name = $route->getName() ?? '';

            return str_starts_with($uri, 'api/v1/')
                && ! str_contains($uri, 'api/v1/testing/')
                && ! str_starts_with($name, 'testing.');
        });
    }

    /** Register the §10.4 model policies. */
    private function registerPolicies(): void
    {
        foreach (self::POLICIES as $model => $policy) {
            Gate::policy($model, $policy);
        }
    }

    /**
     * Named, Redis-backed rate limiters (Plan §9.3). Routes are attached to
     * these in their owning phases; registering them here makes the names
     * available platform-wide.
     */
    private function registerRateLimiters(): void
    {
        RateLimiter::for('magic-link-request', fn (Request $request) => [
            Limit::perMinute(3)->by('email:'.(string) $request->input('email')),
            Limit::perHour(10)->by('ip:'.(string) $request->ip()),
        ]);

        RateLimiter::for('magic-link-verify', fn (Request $request) => Limit::perMinute(10)->by('ip:'.(string) $request->ip()));

        RateLimiter::for('registration', fn (Request $request) => Limit::perHour(3)->by('ip:'.(string) $request->ip()));

        RateLimiter::for('invitation-accept', fn (Request $request) => Limit::perHour(10)->by('ip:'.(string) $request->ip()));

        // MFA confirmation and challenge attempts (Plan §18, §9.3). Per-user
        // (authenticated) and per-IP, so brute-forcing a 6-digit code or a
        // recovery code is throttled to a structured 429.
        RateLimiter::for('mfa-confirm', fn (Request $request) => [
            Limit::perMinute(5)->by($this->identify($request)),
        ]);

        RateLimiter::for('mfa-challenge', fn (Request $request) => [
            Limit::perMinute(5)->by($this->identify($request)),
            Limit::perMinute(15)->by('ip:'.(string) $request->ip()),
        ]);

        RateLimiter::for('api', fn (Request $request) => Limit::perMinute(120)->by($this->identify($request)));

        RateLimiter::for('finance-sensitive', fn (Request $request) => Limit::perMinute(30)->by($this->identify($request)));

        RateLimiter::for('search', fn (Request $request) => Limit::perMinute(60)->by($this->identify($request)));

        // File uploads (Plan §65; Phase 10F) — per-user upload throttle.
        RateLimiter::for('file-upload', fn (Request $request) => Limit::perMinute(20)->by($this->identify($request)));
    }

    /** Per-user key when authenticated, otherwise per-IP. */
    private function identify(Request $request): string
    {
        return $request->user()?->getAuthIdentifier() !== null
            ? 'user:'.$request->user()->getAuthIdentifier()
            : 'ip:'.(string) $request->ip();
    }
}

<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Audit\Contracts\AuditRecorder;
use App\Domain\Audit\Models\AuditExport;
use App\Domain\Audit\Models\AuditFlaggedEvent;
use App\Domain\Audit\Models\AuditLog;
use App\Domain\Audit\Services\DatabaseAuditRecorder;
use App\Domain\Billing\Contracts\PlanContextResolver;
use App\Domain\Billing\Models\MerchantSubscription;
use App\Domain\Billing\Models\PlatformBillingSettings;
use App\Domain\Billing\Models\PreferredPersonnelFeeRule;
use App\Domain\Billing\Models\SubscriptionInvoice;
use App\Domain\Billing\Models\SubscriptionPlan;
use App\Domain\Billing\Models\SubscriptionPlanPrice;
use App\Domain\Billing\Services\UnboundPlanContextResolver;
use App\Domain\Branches\Models\BranchCashUp;
use App\Domain\Branches\Models\BranchDayRecord;
use App\Domain\Branches\Models\BranchOperatingHour;
use App\Domain\Branches\Models\MerchantBranch;
use App\Domain\Catalogue\Models\Service;
use App\Domain\Catalogue\Models\ServiceCategory;
use App\Domain\Catalogue\Models\ServicePersonnelEligibility;
use App\Domain\Clients\Models\Client;
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
use App\Domain\Invoicing\Contracts\PreferredPersonnelFeeResolver;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Invoicing\Services\RuleBasedPreferredPersonnelFeeResolver;
use App\Domain\Merchants\Models\Merchant;
use App\Domain\Merchants\Models\MerchantUser;
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
use App\Policies\FinanceDisputePolicy;
use App\Policies\FinanceExportPolicy;
use App\Policies\FinancialPeriodLockPolicy;
use App\Policies\InvoicePolicy;
use App\Policies\MerchantBranchPolicy;
use App\Policies\MerchantPolicy;
use App\Policies\MerchantSubscriptionPolicy;
use App\Policies\MerchantUserPolicy;
use App\Policies\PaymentRecordingGroupPolicy;
use App\Policies\PlatformBillingSettingsPolicy;
use App\Policies\PreferredPersonnelFeeRulePolicy;
use App\Policies\QueueEntryPolicy;
use App\Policies\ReceiptPolicy;
use App\Policies\RefundPolicy;
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

        // Merchant→plan binding for the Phase 20 entitlement gate. Phase 20A has no
        // merchant_subscriptions (that is Phase 20B), so the default resolver is unbound
        // (returns null → entitlement-dependent actions deny) and fabricates no subscription.
        $this->app->bind(PlanContextResolver::class, UnboundPlanContextResolver::class);

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
    }

    public function boot(): void
    {
        $this->registerRateLimiters();
        $this->registerPolicies();
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

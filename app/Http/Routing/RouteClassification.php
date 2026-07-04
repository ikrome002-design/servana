<?php

declare(strict_types=1);

namespace App\Http\Routing;

use App\Http\Middleware\EnsureIdempotentRequest;
use Illuminate\Routing\Route;
use Illuminate\Routing\Router;

/**
 * Route-classification registry (Plan §24.1, §24.4; Phase R4 seam + Phase 10
 * REM-ROUTE-001 completion).
 *
 * A route declares its class in route defaults under {@see KEY}; this class is the
 * single source of truth for (a) the idempotency coverage guard the R4 phase
 * shipped ({@see financialRoutesMissingIdempotency()}, kept intact), and (b) the
 * full per-class middleware contract Phase 10 enforces
 * ({@see requiredMiddlewareMissing()} / {@see forbiddenMiddlewarePresent()}).
 *
 * The VALIDATION_EXEMPT allowlist is the *one* explicit place a mutation route may
 * opt out of body validation, with a written reason — used by the
 * RouteSecurityContractTest feature test.
 */
final class RouteClassification
{
    /** Route-defaults key carrying the {@see RouteClass} value. */
    public const KEY = 'route_class';

    /**
     * Mutation routes that legitimately accept no request body, so a Form Request
     * would validate nothing. Each entry is an explicit, reviewed exception with a
     * reason; authorization for these routes is enforced by policy/permission
     * middleware (not body validation). Keyed by route name.
     *
     * @var array<string, string>
     */
    public const VALIDATION_EXEMPT = [
        'auth.logout' => 'No request body; tears down the authenticated session.',
        'auth.mfa.enroll' => 'No request body; provisions and returns a new TOTP secret/Qns.',
        'auth.mfa.recovery-codes.regenerate' => 'No request body; gated by RequireFreshMfa step-up.',
        'branches.archive' => 'No request body; state transition authorized by branches.create permission + BranchPolicy.',
        'branches.day.open' => 'No request body; authorized by day.open_close permission.',
        'branches.day.close' => 'No request body; authorized by day.open_close permission.',
        'staff-invitations.resend' => 'No request body; {invitation} binding + StaffInvitationPolicy.',
        'staff-invitations.revoke' => 'No request body; {invitation} binding + StaffInvitationPolicy.',
        'staff.suspend' => 'No request body; {staff} binding + StaffProfilePolicy.',
        'staff.activate' => 'No request body; {staff} binding + StaffProfilePolicy.',
        'staff.deactivate' => 'No request body; {staff} binding + StaffProfilePolicy.',
        'staff.permissions.destroy' => 'No request body; {staff}+{permission} bindings + MerchantUserPolicy.',
        'files.download-link' => 'No request body; issues a signed link, authorized by FileAccessService.',
        'services.archive' => 'No request body; state transition authorized by service.archive permission + ServicePolicy.',
        'services.eligibility.destroy' => 'No request body; {service}+{staff} bindings + personnel.eligibility.manage.',
        'appointments.check-in' => 'No request body; {appointment} binding + appointment.check_in + AppointmentPolicy; branch-day-open enforced in the action.',
        'appointments.no-show' => 'No request body; {appointment} binding + appointment.cancel + AppointmentPolicy; distinct MarkAppointmentNoShow action.',
        'queue.call' => 'No request body; {queueEntry} binding + queue.assign + QueueEntryPolicy; revalidates personnel in the action.',
        'queue.start' => 'No request body; {queueEntry} binding + queue.assign + QueueEntryPolicy; revalidates personnel in the action.',
        'queue.complete' => 'No request body; {queueEntry} binding + queue.assign + QueueEntryPolicy; releases the active queue position.',
        'queue.no-show' => 'No request body; {queueEntry} binding + queue.assign + QueueEntryPolicy; distinct MarkQueueEntryNoShow action.',
        // Phase 17 invoicing — bodiless mutations (all authoritative values derived
        // server-side from the locked invoice/sources).
        'invoices.finalize' => 'No request body; {invoice} binding + invoice.create + InvoicePolicy; financial_mutation idempotency; FinalizeInvoice derives number+snapshots under lock.',
        'invoices.void.execute' => 'No request body; {invoice} binding + invoice.void.request_or_execute_as_policy + InvoicePolicy + RequireFreshMfa; ExecuteInvoiceVoid (void_pending → voided).',
        'invoices.void.reject' => 'No request body; {invoice} binding + invoice.void.request_or_execute_as_policy + InvoicePolicy; RejectInvoiceVoid restores the prior payable state.',
        // Phase 18B — bodiless whole-group validation (the group is the ULID route
        // param; the decision + amounts are derived server-side under lock).
        'payment-recording-groups.validate' => 'No request body; {paymentRecordingGroup} binding + customer_payment.validate + PaymentRecordingGroupPolicy + financial_mutation idempotency; ValidatePaymentRecordingGroup validates the whole group under lock (maker != checker in the action).',
        'payment-recording-groups.resubmit' => 'No request body; {paymentRecordingGroup} binding + customer_payment.reference_correct + PaymentRecordingGroupPolicy + financial_mutation idempotency; ResubmitPaymentRecordingGroup returns a corrected group to pending_validation under lock.',
        'receipts.download-link' => 'No request body; {receipt} binding + receipt.view + ReceiptPolicy; issues a short-lived signed Phase 10F download link (authorization re-checked at issuance and at the byte stream).',
        'refunds.approve' => 'No request body; {refund} binding + refund.approve + RefundPolicy + RequireFreshMfa + financial_mutation idempotency; ApproveRefund enforces approver != requester under lock.',
        'refunds.reject' => 'No request body; {refund} binding + refund.approve + RefundPolicy + financial_mutation idempotency; RejectRefund restores the prior paid state non-destructively.',
        'refunds.finalize' => 'No request body; {refund} binding + refund.finalize + RefundPolicy + RequireFreshMfa + financial_mutation idempotency; FinalizeRefund reduces the recognised balance additively under lock.',
        'finance-disputes.start-review' => 'No request body; {financeDispute} binding + finance_dispute.manage + FinanceDisputePolicy; StartFinanceDisputeReview transitions open → under_review without mutating the disputed source.',
        // Phase 18B — bodiless cash-up state transitions (the cash-up is the ULID route
        // param; expected totals are server-derived under lock; counted values are the
        // Branch Manager's stored draft). reject / request-correction carry a reason
        // (CashUpDecisionRequest) and are therefore NOT exempt.
        'cash-ups.submit' => 'No request body; {cashUp} binding + branch.cash_up.submit + CashUpPolicy + financial_mutation idempotency; SubmitCashUp re-snapshots the server expected totals under lock (draft → submitted).',
        'cash-ups.resubmit' => 'No request body; {cashUp} binding + branch.cash_up.submit + CashUpPolicy + financial_mutation idempotency; ResubmitCashUp re-snapshots under lock (correction_requested → submitted).',
        'cash-ups.approve' => 'No request body; {cashUp} binding + cash_up.approve + CashUpPolicy + financial_mutation idempotency; ApproveCashUp enforces approver != submitter under lock (submitted → approved).',
        'cash-ups.lock' => 'No request body; {cashUp} binding + cash_up.approve + CashUpPolicy + financial_mutation idempotency; LockApprovedCashUp transitions approved → locked under lock.',
        // Phase 18B — bodiless period-reopen governance steps (the lock is the ULID route
        // param; the reopen reason is captured at the request step). approve / execute
        // carry no body; the request step DOES carry a reason and is therefore not exempt.
        'period-locks.reopen.approve' => 'No request body; {periodLock} binding + merchant.period_reopen.approve_exception + FinancialPeriodLockPolicy + financial_mutation idempotency; ApprovePeriodReopenException records a distinct MA approval (approver != requester) under lock.',
        'period-locks.reopen.execute' => 'No request body; {periodLock} binding + period_lock.reopen + FinancialPeriodLockPolicy + RequireFreshMfa + financial_mutation idempotency; ExecutePeriodReopen transitions locked → reopened under lock.',
        // Phase 18B — bodiless finance-export actions (the export is the ULID route param;
        // the request step carries type + reason and is therefore NOT exempt).
        'finance-exports.download-link' => 'No request body; {financeExport} binding + finance_export.download + FinanceExportPolicy; issues a signed Phase 10F link (authorization re-checked at issuance and the byte stream) and records atomic download accounting.',
        'finance-exports.revoke' => 'No request body; {financeExport} binding + finance_export.create + FinanceExportPolicy; RevokeFinanceExport transitions ready → revoked under lock.',
    ];

    public static function of(Route $route): ?RouteClass
    {
        $value = $route->defaults[self::KEY] ?? null;

        return is_string($value) ? RouteClass::tryFrom($value) : null;
    }

    /**
     * Required middleware (substrings) that this route's class mandates but the
     * route is missing. Empty when fully compliant.
     *
     * @return list<string>
     */
    public static function requiredMiddlewareMissing(Route $route): array
    {
        $class = self::of($route);

        if ($class === null) {
            return [];
        }

        $gathered = self::gathered($route);
        $missing = [];

        foreach ($class->requiredMiddleware() as $needle) {
            if (! self::contains($gathered, $needle)) {
                $missing[] = $needle;
            }
        }

        return $missing;
    }

    /**
     * Forbidden middleware (substrings) that this route's class bans but the route
     * carries anyway. Empty when fully compliant.
     *
     * @return list<string>
     */
    public static function forbiddenMiddlewarePresent(Route $route): array
    {
        $class = self::of($route);

        if ($class === null) {
            return [];
        }

        $gathered = self::gathered($route);
        $present = [];

        foreach ($class->forbiddenMiddleware() as $needle) {
            if (self::contains($gathered, $needle)) {
                $present[] = $needle;
            }
        }

        return $present;
    }

    /**
     * Names of `financial_mutation` routes that are missing the idempotency
     * middleware. Empty when every financial route is protected. (Kept from the
     * R4 seam — the FinancialRouteIdempotencyCoverageTest feature test.)
     *
     * @param  iterable<Route>  $routes
     * @return list<string>
     */
    public static function financialRoutesMissingIdempotency(iterable $routes): array
    {
        $missing = [];

        foreach ($routes as $route) {
            if (self::of($route) !== RouteClass::FinancialMutation) {
                continue;
            }

            if (! self::contains(self::gathered($route), EnsureIdempotentRequest::class)) {
                $missing[] = $route->getName() ?? $route->uri();
            }
        }

        return $missing;
    }

    /**
     * The gathered middleware for a route (group + route, aliases resolved).
     *
     * @return list<string>
     */
    private static function gathered(Route $route): array
    {
        $router = app(Router::class);

        return array_values(array_filter(
            $router->gatherRouteMiddleware($route),
            'is_string',
        ));
    }

    /**
     * @param  list<string>  $gathered
     */
    private static function contains(array $gathered, string $needle): bool
    {
        foreach ($gathered as $middleware) {
            if (str_contains($middleware, $needle)) {
                return true;
            }
        }

        return false;
    }
}

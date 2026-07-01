<?php

declare(strict_types=1);

use App\Domain\Branches\Models\MerchantBranch;
use App\Domain\Merchants\Enums\MerchantUserRole;
use App\Domain\Payments\Notifications\FinancePaymentRecordedNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;

uses(RefreshDatabase::class)->group('payments', 'payment-notification');

it('notifies the eligible Finance recipient once when a payment is recorded', function (): void {
    Notification::fake();
    $scn = paymentScenario(500000);

    recordPaymentGroup($scn['frontOffice'], $scn['invoice']->ulid, [cashComponent(100000)])->assertCreated();

    Notification::assertSentTo($scn['finance'], FinancePaymentRecordedNotification::class);
    Notification::assertSentTimes(FinancePaymentRecordedNotification::class, 1);
});

it('does not notify a Finance member assigned to a different branch of the same merchant', function (): void {
    Notification::fake();
    $scn = paymentScenario(500000);
    $otherBranch = MerchantBranch::factory()->create(['merchant_id' => $scn['merchant']->id]);
    [$otherFinance] = branchStaff($scn['merchant'], $otherBranch, MerchantUserRole::Finance);

    recordPaymentGroup($scn['frontOffice'], $scn['invoice']->ulid, [cashComponent(100000)])->assertCreated();

    Notification::assertSentTo($scn['finance'], FinancePaymentRecordedNotification::class);
    Notification::assertNotSentTo($otherFinance, FinancePaymentRecordedNotification::class);
});

it('sends no notification when the recording rolls back', function (): void {
    Notification::fake();
    $scn = paymentScenario(500000);

    recordPaymentGroup($scn['frontOffice'], $scn['invoice']->ulid, [cashComponent(600000)])->assertStatus(422);

    Notification::assertNothingSent();
});

it('notifies Finance for a duplicate-review hold too', function (): void {
    Notification::fake();
    $scn = paymentScenario(500000);

    recordPaymentGroup($scn['frontOffice'], $scn['invoice']->ulid, [referencedComponent(100000, 'mpesa_offline', 'QGX7YT1ABC')])->assertCreated();
    recordPaymentGroup($scn['frontOffice'], $scn['invoice']->ulid, [referencedComponent(100000, 'mpesa_offline', 'QGX7YT1ABC')])->assertStatus(409);

    // One notification for the clean recording + one for the duplicate-review hold.
    Notification::assertSentTimes(FinancePaymentRecordedNotification::class, 2);
});

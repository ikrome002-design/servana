<?php

declare(strict_types=1);

use App\Domain\Branches\Enums\CashUpStatus;
use App\Domain\Branches\Models\BranchCashUp;
use App\Domain\Branches\Models\MerchantBranch;
use App\Domain\Merchants\Enums\MerchantUserRole;
use App\Domain\Payments\Enums\PaymentMethod;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class)->group('payments', 'cash-up', 'isolation');

it('returns 404 for a foreign-tenant cash-up ULID (no existence leak)', function (): void {
    $scn = cashUpScenario();
    cashUpValidatedComponent($scn, PaymentMethod::Cash, 100000);
    putDraft($scn, [['method' => 'cash', 'counted_minor' => 100000]])->assertOk();
    $ulid = (string) BranchCashUp::query()->firstOrFail()->ulid;

    $other = cashUpScenario();
    test()->actingAs($other['finance'], 'sanctum')->getJson("/api/v1/cash-ups/{$ulid}")->assertNotFound();
    test()->actingAs($other['finance'], 'sanctum')
        ->withHeader('Idempotency-Key', (string) Str::uuid())
        ->postJson("/api/v1/cash-ups/{$ulid}/approve")->assertNotFound();
});

it('denies a same-tenant Finance user with no access to the cash-up branch (403)', function (): void {
    $scn = cashUpScenario();
    cashUpValidatedComponent($scn, PaymentMethod::Cash, 100000);
    putDraft($scn, [['method' => 'cash', 'counted_minor' => 100000]])->assertOk();
    $ulid = (string) BranchCashUp::query()->firstOrFail()->ulid;
    cashUpPost($scn['branchManager'], "/api/v1/cash-ups/{$ulid}/submit")->assertOk();

    // A second branch in the SAME merchant, and a Finance user assigned only there.
    $otherBranch = MerchantBranch::factory()->create(['merchant_id' => $scn['merchant']->id]);
    [$otherFinance] = branchStaff($scn['merchant'], $otherBranch, MerchantUserRole::Finance);

    cashUpPost($otherFinance, "/api/v1/cash-ups/{$ulid}/approve")->assertForbidden();
    expect(BranchCashUp::query()->firstOrFail()->status)->toBe(CashUpStatus::Submitted);
});

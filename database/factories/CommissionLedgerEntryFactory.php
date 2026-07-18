<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Branches\Models\MerchantBranch;
use App\Domain\Compensation\Enums\CommissionLedgerEntryType;
use App\Domain\Compensation\Enums\CommissionLedgerStatus;
use App\Domain\Compensation\Enums\CompensationModel;
use App\Domain\Compensation\Models\CommissionLedgerEntry;
use App\Domain\Compensation\Models\CommissionRule;
use App\Domain\Compensation\Models\PersonnelCompensationPlan;
use App\Domain\Hr\Models\StaffProfile;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Invoicing\Models\InvoiceItem;
use App\Domain\Payments\Models\PaymentRecordingGroup;
use App\Domain\Payments\Models\PaymentValidationEvent;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<CommissionLedgerEntry>
 *
 * Default: a valid EARNED percentage row anchored on one branch so every composite FK agrees on
 * the merchant. Builds the coherent graph (staff → rule → plan → invoice → item → group →
 * validation event). Not created via the domain earner — tests that assert earning use the
 * domain services; this factory exists for schema/tenancy coverage and fixtures.
 */
class CommissionLedgerEntryFactory extends Factory
{
    protected $model = CommissionLedgerEntry::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'ulid' => (string) Str::ulid(),
            'branch_id' => MerchantBranch::factory(),
            'merchant_id' => fn (array $a) => MerchantBranch::query()->whereKey($a['branch_id'])->value('merchant_id'),
            'staff_profile_id' => fn (array $a) => StaffProfile::factory()->create([
                'merchant_id' => $a['merchant_id'],
                'primary_branch_id' => $a['branch_id'],
            ])->id,
            'commission_rule_id' => fn (array $a) => CommissionRule::factory()->create([
                'merchant_id' => $a['merchant_id'],
                'branch_id' => $a['branch_id'],
            ])->id,
            'compensation_plan_id' => fn (array $a) => PersonnelCompensationPlan::factory()->create([
                'merchant_id' => $a['merchant_id'],
                'branch_id' => $a['branch_id'],
                'staff_profile_id' => $a['staff_profile_id'],
                'commission_rule_id' => $a['commission_rule_id'],
                'compensation_model' => CompensationModel::SalaryPlusCommission,
                'salary_amount_minor' => 5000000,
                'salary_currency' => 'KES',
                'salary_period' => 'monthly',
            ])->id,
            'invoice_id' => fn (array $a) => Invoice::factory()->issued()->create([
                'merchant_id' => $a['merchant_id'],
                'branch_id' => $a['branch_id'],
            ])->id,
            'invoice_item_id' => fn (array $a) => InvoiceItem::factory()->create([
                'invoice_id' => $a['invoice_id'],
                'staff_profile_id' => $a['staff_profile_id'],
                'eligible_for_commission' => true,
            ])->id,
            'service_session_id' => fn (array $a) => InvoiceItem::query()->whereKey($a['invoice_item_id'])->value('service_session_id'),
            'payment_record_id' => null,
            'payment_validation_event_id' => fn (array $a) => PaymentValidationEvent::factory()->create([
                'merchant_id' => $a['merchant_id'],
                'branch_id' => $a['branch_id'],
                'invoice_id' => $a['invoice_id'],
                'payment_recording_group_id' => PaymentRecordingGroup::factory()->create([
                    'merchant_id' => $a['merchant_id'],
                    'branch_id' => $a['branch_id'],
                    'invoice_id' => $a['invoice_id'],
                ])->id,
            ])->id,
            'source_entry_id' => null,
            'entry_type' => CommissionLedgerEntryType::Earned,
            'reversal_reason' => null,
            'calculation_basis_minor' => 500000,
            'rate_basis_points' => 1000,
            'fixed_rate_minor' => null,
            'amount_minor' => 50000,
            'currency' => 'KES',
            'earned_at' => CarbonImmutable::now(),
            'status' => CommissionLedgerStatus::Earned,
            'payout_item_id' => null,
            'created_by' => null,
            'approved_by' => null,
            'created_at' => CarbonImmutable::now(),
        ];
    }
}

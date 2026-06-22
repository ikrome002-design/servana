<?php

declare(strict_types=1);

use App\Domain\Tenancy\Concerns\BelongsToBranch;
use App\Domain\Tenancy\Concerns\BelongsToMerchant;
use App\Domain\Tenancy\TenantOwnership;
use Illuminate\Database\Eloquent\Model;

uses()->group('tenancy', 'isolation');

/*
 | Model trait coverage (Plan §8.2; ADR-002; R5). Every tenant-/branch-owned model
 | must carry the correct tenancy traits, and the registry must stay in sync with
 | the DB ownership classification.
 */

/** @return array<string> recursive trait names used by a class. */
function classTraits(string $class): array
{
    return array_keys(class_uses_recursive($class));
}

it('every tenant-owned model uses BelongsToMerchant', function (): void {
    foreach (TenantOwnership::MODELS as $class => $kind) {
        expect(classTraits($class))->toContain(BelongsToMerchant::class);
    }
});

it('every branch-scoped model also uses BelongsToBranch', function (): void {
    foreach (TenantOwnership::MODELS as $class => $kind) {
        if ($kind === 'branch') {
            expect(classTraits($class))->toContain(BelongsToBranch::class);
        }
    }
});

it('keeps the model registry consistent with the DB ownership lists', function (): void {
    // Every model maps to a table that is classified branch_owned or tenant_owned.
    foreach (TenantOwnership::MODELS as $class => $kind) {
        $table = (new $class)->getTable();

        $classified = in_array($table, TenantOwnership::BRANCH_OWNED, true)
            || in_array($table, TenantOwnership::TENANT_OWNED, true);

        expect($classified)->toBeTrue();
    }
});

it('proves a deliberate trait violation would be caught', function (): void {
    // A branch-owned model that forgot BelongsToMerchant must fail the check.
    $offender = new class extends Model
    {
        use BelongsToBranch;
    };

    expect(classTraits($offender::class))->not->toContain(BelongsToMerchant::class);
    // The coverage rule (assert "contains BelongsToMerchant") would therefore fail
    // for such a model — demonstrated here without registering it.
});

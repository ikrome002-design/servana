<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Phase UI-03 — deterministic fixture for the deployed-origin browser proof
|--------------------------------------------------------------------------
|
| Creates the smallest set of real database rows the cross-host browser proof
| needs, and prints them as JSON for `scripts/ui03-auth-browser-proof.mjs`.
|
| It is a PROOF FIXTURE, not a seeder and not production code. It is run against
| a DISPOSABLE database (DB_DATABASE=servana_ui03_proof), never against the
| developer's working database, and never in production — the guard below
| refuses to run anywhere near a production environment or a database whose name
| is not the disposable one.
|
| Why these particular rows (each is required by a case the proof must observe):
|
|   TWO merchants, not one. `merchant_users` is UNIQUE(merchant_id, user_id), so
|   a human holds at most ONE membership per merchant — a multi-context user is
|   therefore necessarily a multi-MERCHANT user. A single-merchant fixture cannot
|   exercise account switching at all.
|
|   BRANCH ASSIGNMENTS. Every merchant role except Merchant Admin is
|   branch-scoped, and AccountContextResolver returns no context at all for a
|   branch-scoped membership with no active assignment. Without them the context
|   list is empty and every switch case is vacuous.
|
|   A MANDATORY-MFA TARGET. MfaRequirementResolver is USER-level (any active
|   merchant_admin/finance membership makes MFA mandatory for the whole user), so
|   proving "source assurance is not copied" needs a user who can actually
|   SATISFY MFA on the source and then be challenged again on the target. That is
|   what the confirmed TOTP credential with a KNOWN secret is for. The secret is
|   fixture-only, is printed to stdout for the proof run, and is never committed.
|
| Usage (from the dev container, against the disposable database):
|   php scripts/ui03-browser-fixture.php
*/

use App\Domain\Auth\Models\MfaCredential;
use App\Domain\Branches\Models\BranchUserAssignment;
use App\Domain\Branches\Models\MerchantBranch;
use App\Domain\Merchants\Enums\MerchantUserRole;
use App\Domain\Merchants\Models\Merchant;
use App\Domain\Merchants\Models\MerchantUser;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\DB;

// The imports must come FIRST. `use` aliases apply from their point of declaration onward, so a
// bootstrap placed above them resolves `Kernel::class` as a global class and dies. Pint's
// ordered_imports moves the block here, which is also the correct order to run in.
require __DIR__.'/../vendor/autoload.php';

/** @var Application $app */
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

// ---------------------------------------------------------------------------
// Refuse to run anywhere that is not the disposable proof database.
// ---------------------------------------------------------------------------
$database = (string) config('database.connections.'.config('database.default').'.database');

if (app()->environment('production') || $database !== 'servana_ui03_proof') {
    fwrite(STDERR, "REFUSED: this fixture only runs against the disposable 'servana_ui03_proof' database.\n");
    fwrite(STDERR, "         environment={$database} app_env=".app()->environment()."\n");
    exit(2);
}

// A FIXED secret so the proof script can compute valid TOTP codes. Fixture-only:
// it authenticates a throwaway user in a throwaway database.
$totpSecret = 'AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA';

$result = DB::transaction(static function () use ($totpSecret): array {
    $sourceMerchant = Merchant::factory()->active()->create(['name' => 'UI03 Source Studio']);
    $targetMerchant = Merchant::factory()->active()->create(['name' => 'UI03 Target Spa']);

    $sourceBranch = MerchantBranch::factory()->create([
        'merchant_id' => $sourceMerchant->id,
        'name' => 'UI03 Source Branch',
    ]);
    $targetBranch = MerchantBranch::factory()->create([
        'merchant_id' => $targetMerchant->id,
        'name' => 'UI03 Target Branch',
    ]);

    /** Attach a membership plus its active branch assignment. */
    $member = static function (
        User $user,
        Merchant $merchant,
        MerchantBranch $branch,
        MerchantUserRole $role,
    ): MerchantUser {
        $membership = MerchantUser::factory()->create([
            'merchant_id' => $merchant->id,
            'user_id' => $user->id,
            'role' => $role,
        ]);

        // Merchant Admin is not branch-scoped; every other role must hold an
        // ACTIVE assignment or it has no context to enter.
        if ($role !== MerchantUserRole::MerchantAdmin) {
            BranchUserAssignment::factory()->create([
                'merchant_user_id' => $membership->id,
                'branch_id' => $branch->id,
                'merchant_id' => $merchant->id,
            ]);
        }

        return $membership;
    };

    // U1 — multi-context, NO mandatory role. Front Office (source) → Audit (target).
    $multi = User::factory()->create([
        'name' => 'UI03 Multi Context',
        'email' => 'ui03.multi@servana.test',
    ]);
    $member($multi, $sourceMerchant, $sourceBranch, MerchantUserRole::FrontOffice);
    $member($multi, $targetMerchant, $targetBranch, MerchantUserRole::Audit);

    // U2 — multi-context WITH a mandatory-MFA target. Front Office (source) → Finance (target).
    $mfa = User::factory()->create([
        'name' => 'UI03 Mfa Context',
        'email' => 'ui03.mfa@servana.test',
    ]);
    $member($mfa, $sourceMerchant, $sourceBranch, MerchantUserRole::FrontOffice);
    $member($mfa, $targetMerchant, $targetBranch, MerchantUserRole::Finance);

    MfaCredential::factory()->confirmed()->create([
        'user_id' => $mfa->id,
        'secret_encrypted' => $totpSecret,
    ]);

    // U3 — single context. Used for the wrong-host link and wrong-account deep link.
    $single = User::factory()->create([
        'name' => 'UI03 Single Context',
        'email' => 'ui03.single@servana.test',
    ]);
    $member($single, $sourceMerchant, $sourceBranch, MerchantUserRole::Personnel);

    return [
        'source_merchant' => ['ulid' => $sourceMerchant->ulid, 'name' => $sourceMerchant->name],
        'target_merchant' => ['ulid' => $targetMerchant->ulid, 'name' => $targetMerchant->name],
        'users' => [
            'multi' => [
                'email' => $multi->email,
                'source_account_key' => 'merchant_front_office',
                'target_account_key' => 'merchant_audit',
                'mfa_mandatory' => false,
            ],
            'mfa' => [
                'email' => $mfa->email,
                'source_account_key' => 'merchant_front_office',
                'target_account_key' => 'merchant_finance',
                'mfa_mandatory' => true,
                'totp_secret' => $totpSecret,
            ],
            'single' => [
                'email' => $single->email,
                'source_account_key' => 'merchant_personnel',
                'mfa_mandatory' => false,
            ],
        ],
    ];
});

echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), PHP_EOL;

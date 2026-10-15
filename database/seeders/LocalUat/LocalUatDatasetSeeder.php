<?php

declare(strict_types=1);

namespace Database\Seeders\LocalUat;

use App\Domain\Auth\Models\MfaCredential;
use App\Domain\Auth\Seeders\PermissionSeeder;
use App\Domain\Billing\Actions\CreateTrialSubscription;
use App\Domain\Billing\Actions\IssueSubscriptionInvoice;
use App\Domain\Billing\Enums\BillingInterval;
use App\Domain\Billing\Models\MerchantSubscription;
use App\Domain\Billing\Models\PlanEntitlement;
use App\Domain\Billing\Models\SubscriptionPlan;
use App\Domain\Billing\Models\SubscriptionPlanPrice;
use App\Domain\Branches\Models\BranchOperatingHour;
use App\Domain\Branches\Models\BranchUserAssignment;
use App\Domain\Branches\Models\MerchantBranch;
use App\Domain\Catalogue\Models\Service;
use App\Domain\Catalogue\Models\ServiceCategory;
use App\Domain\Catalogue\Models\ServicePersonnelEligibility;
use App\Domain\Clients\Enums\ConsentChannel;
use App\Domain\Clients\Enums\ConsentState;
use App\Domain\Clients\Models\Client;
use App\Domain\Clients\Models\ClientConsent;
use App\Domain\Compensation\Models\CommissionRule;
use App\Domain\Compensation\Models\PersonnelCompensationPlan;
use App\Domain\Hr\Models\StaffProfile;
use App\Domain\Merchants\Enums\MerchantUserRole;
use App\Domain\Merchants\Models\Merchant;
use App\Domain\Merchants\Models\MerchantProfile;
use App\Domain\Merchants\Models\MerchantUser;
use App\Domain\PlatformAccess\Models\PlatformAccessMembership;
use App\Domain\Scheduling\Enums\ServiceSessionStatus;
use App\Domain\Scheduling\Models\Appointment;
use App\Domain\Scheduling\Models\PersonnelAvailability;
use App\Domain\Scheduling\Models\ServiceSession;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use PragmaRX\Google2FA\Google2FA;
use RuntimeException;

/**
 * LOCAL-UAT-001 — the local browser acceptance dataset.
 *
 * Gives every one of the eight account experiences a real, signed-in identity and enough tenancy,
 * catalogue, staff, client and compensation data for its pages to show something meaningful, so the
 * product owner can explore the application in an ordinary browser on the eight `*.servana.test`
 * hosts. It is built from the repository's own factories and billing actions (the Phase 24
 * PerformanceDatasetSeeder precedent), so it cannot drift from the schema or bypass a model invariant.
 *
 * What it deliberately does NOT create:
 *   - financial history (invoices, payments, validations, receipts, ledger entries, cash-ups) and
 *     audit events. Those must come from the real application paths, so the acceptance workflow
 *     (`tests/e2e/local-uat/`) and the owner create them through the browser and the API.
 *   - any Wallet / Refer & Earn / SMS provider state. Gate W stays closed.
 *
 * Two identity sets:
 *   - OWNER identities (`<role>@acacia.servana.test`, `admin@citrus.servana.test`, ...) are for the
 *     product owner. Automation never signs in as them, so the MFA-mandatory roles still meet the
 *     genuine first-time enrolment flow.
 *   - AUTOMATION identities (`uat.<role>@...`) are for the scripted acceptance run. The MFA-mandatory
 *     ones hold a confirmed TOTP credential whose secret is written ONLY to the git-ignored
 *     `storage/app/local-uat/automation-mfa.json`, so the run can answer the real MFA challenge.
 *     MFA is still required and still verified; nothing is bypassed.
 *
 * SAFETY: refuses to run outside APP_ENV=local or against a non-local database host, and is never
 * called by DatabaseSeeder. IDEMPOTENT: a second run finds the marker merchant, creates nothing, and
 * only rewrites the two git-ignored manifest files.
 *
 * Run (local only):  php artisan db:seed --class="Database\Seeders\LocalUat\LocalUatDatasetSeeder"
 */
final class LocalUatDatasetSeeder extends Seeder
{
    /** Marker for idempotency: the primary demo merchant's slug. */
    public const MARKER_SLUG = 'acacia-wellness-studio-local-uat';

    public const SECOND_MERCHANT_SLUG = 'baobab-grooming-lounge-local-uat';

    /** Git-ignored output directory (storage/app/* is ignored). */
    private const OUTPUT_DIR = 'app/local-uat';

    private const FAKER_SEED = 2026;

    /** @var array<string, User> */
    private array $users = [];

    /** @var array<string, StaffProfile> */
    private array $staff = [];

    public function run(): void
    {
        $this->guardEnvironment();

        $this->call(PermissionSeeder::class);

        $existing = Merchant::query()->where('slug', self::MARKER_SLUG)->first();

        if ($existing !== null) {
            $this->command->info('LOCAL-UAT dataset already present — nothing created. Refreshing manifest files only.');
        } else {
            fake()->seed(self::FAKER_SEED);

            DB::transaction(function (): void {
                $this->seedDataset();
            });

            $this->command->info('LOCAL-UAT dataset created.');
        }

        $this->writeManifests();
    }

    /** Refuse anything that is not a local developer database. */
    private function guardEnvironment(): void
    {
        $connection = (string) config('database.default');
        $host = (string) config("database.connections.{$connection}.host");
        $database = (string) config("database.connections.{$connection}.database");

        if (! app()->environment('local')) {
            throw new RuntimeException(sprintf('REFUSED: LOCAL-UAT data seeds only when APP_ENV=local (got "%s").', app()->environment()));
        }

        if (! in_array($host, ['postgres', '127.0.0.1', 'localhost'], true)) {
            throw new RuntimeException(sprintf('REFUSED: database host "%s" is not a local development host.', $host));
        }

        if (preg_match('/prod|live|staging/i', $database) === 1) {
            throw new RuntimeException(sprintf('REFUSED: database "%s" does not look like a local development database.', $database));
        }
    }

    private function seedDataset(): void
    {
        $platformAdmin = $this->platformAdministrator('admin@citrus.servana.test', 'Citrus Platform Admin');
        $this->platformAdministrator('uat.admin@citrus.servana.test', 'UAT Automation Platform Admin', withMfa: true);

        [$starterPrice, $growthPrice] = $this->subscriptionPlans($platformAdmin);

        // --- Merchant A: the primary demo tenant, two branches ---------------------------------
        $acacia = $this->merchant('Acacia Wellness Studio', self::MARKER_SLUG, 'Spa', 'acacia');
        $westlands = $this->branch($acacia, 'Westlands', 'WST', 'Westlands', allDay: true);
        $kilimani = $this->branch($acacia, 'Kilimani', 'KIL', 'Kilimani', allDay: false);

        $owner = $this->member($acacia, null, MerchantUserRole::MerchantAdmin, 'owner@acacia.servana.test', 'Achieng Otieno');
        $this->member($acacia, null, MerchantUserRole::MerchantAdmin, 'uat.owner@acacia.servana.test', 'UAT Automation Owner', withMfa: true);

        $owned = [
            ['branch@acacia.servana.test', 'Brian Mwangi', MerchantUserRole::BranchManager, 'Branch manager'],
            ['hr@acacia.servana.test', 'Halima Hassan', MerchantUserRole::Hr, 'HR manager'],
            ['finance@acacia.servana.test', 'Faith Njeri', MerchantUserRole::Finance, 'Finance officer'],
            ['frontoffice@acacia.servana.test', 'Grace Wambui', MerchantUserRole::FrontOffice, 'Front office'],
            ['stylist@acacia.servana.test', 'Wanjiru Kamau', MerchantUserRole::Personnel, 'Senior stylist'],
            ['barber@acacia.servana.test', 'Otieno Ouma', MerchantUserRole::Personnel, 'Barber'],
            ['therapist@acacia.servana.test', 'Mercy Chebet', MerchantUserRole::Personnel, 'Massage therapist'],
            ['audit@acacia.servana.test', 'Daniel Kiprop', MerchantUserRole::Audit, 'Internal auditor'],
            // Automation identities for the scripted acceptance run (never the owner's).
            ['uat.branch@acacia.servana.test', 'UAT Automation Branch', MerchantUserRole::BranchManager, 'Branch manager'],
            ['uat.hr@acacia.servana.test', 'UAT Automation HR', MerchantUserRole::Hr, 'HR manager'],
            ['uat.finance@acacia.servana.test', 'UAT Automation Finance', MerchantUserRole::Finance, 'Finance officer'],
            ['uat.frontoffice@acacia.servana.test', 'UAT Automation Front Office', MerchantUserRole::FrontOffice, 'Front office'],
            ['uat.stylist@acacia.servana.test', 'UAT Automation Stylist', MerchantUserRole::Personnel, 'Stylist'],
            ['uat.audit@acacia.servana.test', 'UAT Automation Audit', MerchantUserRole::Audit, 'Internal auditor'],
        ];

        foreach ($owned as $index => [$email, $name, $role, $title]) {
            $this->member($acacia, $westlands, $role, $email, $name, title: $title, phoneSuffix: 201 + $index,
                withMfa: str_starts_with($email, 'uat.') && $role === MerchantUserRole::Finance);
        }

        // A second-branch team, so branch scoping is observable (Westlands users never see Kilimani rows).
        $this->member($acacia, $kilimani, MerchantUserRole::FrontOffice, 'frontoffice.kilimani@acacia.servana.test', 'Lilian Atieno', title: 'Front office', phoneSuffix: 231);
        $this->member($acacia, $kilimani, MerchantUserRole::Personnel, 'nails.kilimani@acacia.servana.test', 'Joy Akinyi', title: 'Nail technician', phoneSuffix: 232);

        $westlandsServices = $this->catalogue($acacia, $westlands, [
            'Hair' => [['Wash and blow-dry', 150000, 45], ['Braiding (medium)', 450000, 120], ['Silk press', 300000, 90]],
            'Grooming' => [['Classic haircut', 80000, 30], ['Beard trim and shape', 50000, 30]],
            'Spa and massage' => [['Swedish massage', 350000, 60], ['Deep tissue massage', 400000, 60]],
            'Nails' => [['Gel manicure', 200000, 45]],
        ]);
        $kilimaniServices = $this->catalogue($acacia, $kilimani, [
            'Nails' => [['Gel manicure', 200000, 45], ['Pedicure', 180000, 45]],
            'Hair' => [['Wash and blow-dry', 150000, 45]],
        ]);

        $westlandsPersonnel = ['stylist@acacia.servana.test', 'barber@acacia.servana.test', 'therapist@acacia.servana.test', 'uat.stylist@acacia.servana.test'];
        $this->eligibilityAndAvailability($acacia, $westlands, $westlandsServices, $westlandsPersonnel);
        $this->eligibilityAndAvailability($acacia, $kilimani, $kilimaniServices, ['nails.kilimani@acacia.servana.test']);

        $westlandsClients = $this->clients($acacia, $westlands, [
            'Amina Wanjiku', 'Peter Odhiambo', 'Sarah Muthoni', 'James Kariuki', 'Esther Nyambura',
            'Kevin Omondi', 'Lucy Wairimu', 'Samuel Kiptoo', 'Naliaka Wekesa', 'Hassan Abdi',
            'Ruth Moraa', 'Collins Mutua',
        ], phoneBase: 101);
        $kilimaniClients = $this->clients($acacia, $kilimani, ['Dorothy Achieng', 'Victor Kimani', 'Agnes Chepkoech'], phoneBase: 151);

        // Personnel own-scope history: completed, NOT yet invoiced sessions from yesterday (they appear
        // on the Front Office invoice-create screen) with SMS opt-in for the served clients.
        $this->servedHistory($acacia, $westlands, $westlandsClients, $westlandsServices);

        // Upcoming appointments (tomorrow), non-overlapping per personnel.
        $this->upcomingAppointments($acacia, $westlands, $westlandsClients, $westlandsServices, $owner);
        $this->upcomingAppointments($acacia, $kilimani, $kilimaniClients, $kilimaniServices, $owner, personnel: ['nails.kilimani@acacia.servana.test']);

        // Compensation: one approved branch commission rule and active plans for Westlands personnel.
        $this->compensation($acacia, $westlands, $westlandsPersonnel);

        // Subscription: a trial bound through the real action, plus one issued invoice (Wallet
        // registration stays `unregistered` — Gate W is closed, so it can never be paid locally).
        $subscription = app(CreateTrialSubscription::class)->handle($acacia, $growthPrice, $owner);
        app(IssueSubscriptionInvoice::class)->handle($subscription, $platformAdmin);

        // --- Merchant B: an unrelated tenant, for cross-tenant isolation checks -----------------
        $baobab = $this->merchant('Baobab Grooming Lounge', self::SECOND_MERCHANT_SLUG, 'Barbershop', 'baobab');
        $cbd = $this->branch($baobab, 'Nairobi CBD', 'CBD', 'Nairobi', allDay: false);
        $baobabOwner = $this->member($baobab, null, MerchantUserRole::MerchantAdmin, 'owner@baobab.servana.test', 'Kamau Njoroge');
        $this->member($baobab, $cbd, MerchantUserRole::FrontOffice, 'frontoffice@baobab.servana.test', 'Zawadi Mwende', title: 'Front office', phoneSuffix: 241);
        $this->member($baobab, $cbd, MerchantUserRole::Personnel, 'barber@baobab.servana.test', 'Brian Otieno', title: 'Barber', phoneSuffix: 242);
        $cbdServices = $this->catalogue($baobab, $cbd, ['Grooming' => [['Skin fade', 70000, 30], ['Hot towel shave', 60000, 30]]]);
        $this->eligibilityAndAvailability($baobab, $cbd, $cbdServices, ['barber@baobab.servana.test']);
        $this->clients($baobab, $cbd, ['Mwangi Kiprono', 'Halima Yusuf'], phoneBase: 171);
        app(CreateTrialSubscription::class)->handle($baobab, $starterPrice, $baobabOwner);
    }

    private function platformAdministrator(string $email, string $name, bool $withMfa = false): User
    {
        $user = User::factory()->platformStaff()->create(['email' => $email, 'name' => $name]);

        PlatformAccessMembership::factory()->create(['user_id' => $user->id]);

        if ($withMfa) {
            $this->confirmedTotp($user);
        }

        return $this->users[$email] = $user;
    }

    /** @return array{0: SubscriptionPlanPrice, 1: SubscriptionPlanPrice} */
    private function subscriptionPlans(User $createdBy): array
    {
        $prices = [];

        foreach ([['local_uat_starter', 'Starter', 250000, false, 10], ['local_uat_growth', 'Growth', 650000, true, 20]] as [$key, $name, $amount, $sms, $sort]) {
            $plan = SubscriptionPlan::factory()->create([
                'key' => $key,
                'name' => $name,
                'description' => "{$name} plan (local UAT fixture).",
                'tier' => strtolower($name),
                'sort_order' => $sort,
            ]);

            PlanEntitlement::query()->create([
                'plan_id' => $plan->id,
                'entitlement_key' => 'sms',
                'limit_int' => null,
                'enabled' => $sms,
            ]);

            $prices[] = SubscriptionPlanPrice::factory()->create([
                'plan_id' => $plan->id,
                'amount_minor' => $amount,
                'currency' => 'KES',
                'billing_interval' => BillingInterval::Monthly,
                'effective_from' => '2026-01-01',
                'created_by' => $createdBy->id,
            ]);
        }

        return [$prices[0], $prices[1]];
    }

    private function merchant(string $name, string $slug, string $category, string $emailDomain): Merchant
    {
        $merchant = Merchant::factory()->active()->create(['name' => $name, 'slug' => $slug]);

        MerchantProfile::factory()->create([
            'merchant_id' => $merchant->id,
            'business_category' => $category,
            'contact_email' => "hello@{$emailDomain}.servana.test",
            'contact_phone' => '+254700000'.($emailDomain === 'acacia' ? '010' : '020'),
            'receipt_display_name' => $name,
            'address' => $emailDomain === 'acacia' ? 'Woodvale Grove, Westlands' : 'Moi Avenue',
            'town' => 'Nairobi',
            'country' => 'KE',
            'timezone' => 'Africa/Nairobi',
        ]);

        return $merchant;
    }

    /**
     * `allDay` opens the branch 00:00–23:59 every day, so the queue/walk-in workflow (which validates
     * "now + service duration" against operating hours and availability) works whenever the owner
     * happens to test. The other branches keep ordinary hours.
     */
    private function branch(Merchant $merchant, string $name, string $code, string $town, bool $allDay): MerchantBranch
    {
        $branch = MerchantBranch::factory()->create([
            'merchant_id' => $merchant->id,
            'name' => $name,
            'code' => $code,
            'town' => $town,
            'address' => "{$town}, Nairobi",
            'phone' => '+254700000'.str_pad((string) (30 + crc32($code) % 60), 3, '0', STR_PAD_LEFT),
        ]);

        for ($weekday = 0; $weekday <= 6; $weekday++) {
            BranchOperatingHour::query()->create([
                'merchant_id' => $merchant->id,
                'branch_id' => $branch->id,
                'weekday' => $weekday,
                'opens_at' => $allDay ? '00:00:00' : '08:00:00',
                'closes_at' => $allDay ? '23:59:00' : '19:00:00',
                'is_closed' => ! $allDay && $weekday === 0,
            ]);
        }

        return $branch;
    }

    /**
     * An active membership; every branch-scoped role also gets its staff profile and an ACTIVE branch
     * assignment (without one AccountContextResolver offers no context to enter).
     */
    private function member(
        Merchant $merchant,
        ?MerchantBranch $branch,
        MerchantUserRole $role,
        string $email,
        string $name,
        ?string $title = null,
        int $phoneSuffix = 0,
        bool $withMfa = false,
    ): User {
        $user = User::factory()->create(['email' => $email, 'name' => $name]);

        $membership = MerchantUser::factory()->create([
            'merchant_id' => $merchant->id,
            'user_id' => $user->id,
            'role' => $role,
            'last_branch_id' => $branch?->id,
        ]);

        if ($branch !== null) {
            [$first, $last] = array_pad(explode(' ', $name, 2), 2, '');

            $this->staff[$email] = StaffProfile::factory()->create([
                'merchant_user_id' => $membership->id,
                'merchant_id' => $merchant->id,
                'primary_branch_id' => $branch->id,
                'first_name' => $first,
                'last_name' => $last,
                'display_name' => $name,
                'phone' => '+254700000'.str_pad((string) $phoneSuffix, 3, '0', STR_PAD_LEFT),
                'role_title' => $title ?? 'Staff',
                'start_date' => '2026-01-05',
            ]);

            BranchUserAssignment::factory()->create([
                'merchant_user_id' => $membership->id,
                'branch_id' => $branch->id,
                'merchant_id' => $merchant->id,
            ]);
        }

        if ($withMfa) {
            $this->confirmedTotp($user);
        }

        return $this->users[$email] = $user;
    }

    private function confirmedTotp(User $user): void
    {
        MfaCredential::factory()->confirmed()->create([
            'user_id' => $user->id,
            'secret_encrypted' => (new Google2FA)->generateSecretKey(),
        ]);
    }

    /**
     * @param  array<string, list<array{0: string, 1: int, 2: int}>>  $categories  name => [service, price_minor, minutes]
     * @return list<Service>
     */
    private function catalogue(Merchant $merchant, MerchantBranch $branch, array $categories): array
    {
        $services = [];
        $sort = 0;

        foreach ($categories as $categoryName => $items) {
            $category = ServiceCategory::query()->create([
                'merchant_id' => $merchant->id,
                'branch_id' => $branch->id,
                'name' => $categoryName,
                'sort_order' => $sort++,
            ]);

            foreach ($items as [$serviceName, $priceMinor, $minutes]) {
                $services[] = Service::factory()->create([
                    'merchant_id' => $merchant->id,
                    'branch_id' => $branch->id,
                    'category_id' => $category->id,
                    'name' => $serviceName,
                    'description' => null,
                    'price_minor' => $priceMinor,
                    'currency' => 'KES',
                    'duration_minutes' => $minutes,
                ]);
            }
        }

        return $services;
    }

    /**
     * @param  list<Service>  $services
     * @param  list<string>  $personnelEmails
     */
    private function eligibilityAndAvailability(Merchant $merchant, MerchantBranch $branch, array $services, array $personnelEmails): void
    {
        foreach ($personnelEmails as $email) {
            $staff = $this->staff[$email];

            foreach ($services as $service) {
                ServicePersonnelEligibility::query()->create([
                    'merchant_id' => $merchant->id,
                    'branch_id' => $branch->id,
                    'service_id' => $service->id,
                    'staff_profile_id' => $staff->id,
                    'active' => true,
                ]);
            }

            for ($weekday = 0; $weekday <= 6; $weekday++) {
                PersonnelAvailability::query()->create([
                    'merchant_id' => $merchant->id,
                    'branch_id' => $branch->id,
                    'staff_profile_id' => $staff->id,
                    'type' => 'recurring',
                    'weekday' => $weekday,
                    'start_time' => '00:00:00',
                    'end_time' => '23:59:00',
                    'available' => true,
                ]);
            }
        }
    }

    /**
     * @param  list<string>  $names
     * @return list<Client>
     */
    private function clients(Merchant $merchant, MerchantBranch $branch, array $names, int $phoneBase): array
    {
        $clients = [];

        foreach ($names as $offset => $name) {
            $clients[] = Client::factory()->withPhone('+254700000'.str_pad((string) ($phoneBase + $offset), 3, '0', STR_PAD_LEFT))->create([
                'merchant_id' => $merchant->id,
                'branch_id' => $branch->id,
                'full_name' => $name,
                'email_encrypted' => null,
                'notes' => null,
            ]);
        }

        return $clients;
    }

    /**
     * @param  list<Client>  $clients
     * @param  list<Service>  $services
     */
    private function servedHistory(Merchant $merchant, MerchantBranch $branch, array $clients, array $services): void
    {
        $served = [
            ['stylist@acacia.servana.test', 0, 0], ['stylist@acacia.servana.test', 1, 2],
            ['barber@acacia.servana.test', 3, 3], ['therapist@acacia.servana.test', 4, 5],
            ['uat.stylist@acacia.servana.test', 5, 0], ['uat.stylist@acacia.servana.test', 6, 7],
        ];

        $yesterday = CarbonImmutable::now('Africa/Nairobi')->subDay()->setTime(10, 0);

        foreach ($served as $i => [$email, $clientIndex, $serviceIndex]) {
            $start = $yesterday->addMinutes(90 * $i);

            ServiceSession::factory()->create([
                'merchant_id' => $merchant->id,
                'branch_id' => $branch->id,
                'client_id' => $clients[$clientIndex]->id,
                'service_id' => $services[$serviceIndex]->id,
                'staff_profile_id' => $this->staff[$email]->id,
                'queue_entry_id' => null,
                'status' => ServiceSessionStatus::Completed,
                'started_at' => $start->utc(),
                'completed_at' => $start->addMinutes($services[$serviceIndex]->duration_minutes)->utc(),
            ]);

            ClientConsent::factory()->create([
                'merchant_id' => $merchant->id,
                'branch_id' => $branch->id,
                'client_id' => $clients[$clientIndex]->id,
                'channel' => ConsentChannel::Sms,
                'state' => $i === 3 ? ConsentState::OptedOut : ConsentState::OptedIn,
            ]);
        }
    }

    /**
     * @param  list<Client>  $clients
     * @param  list<Service>  $services
     * @param  list<string>  $personnel
     */
    private function upcomingAppointments(
        Merchant $merchant,
        MerchantBranch $branch,
        array $clients,
        array $services,
        User $createdBy,
        array $personnel = ['stylist@acacia.servana.test', 'barber@acacia.servana.test', 'therapist@acacia.servana.test'],
    ): void {
        $tomorrow = CarbonImmutable::now('Africa/Nairobi')->addDay()->setTime(10, 0);

        foreach ($personnel as $p => $email) {
            for ($slot = 0; $slot < 2; $slot++) {
                $clientIndex = ($p * 2 + $slot + 7) % count($clients);
                $service = $services[($p + $slot) % count($services)];
                $start = $tomorrow->addHours($slot * 3);
                $staffId = $this->staff[$email]->id;

                Appointment::factory()->confirmed()->between($start->utc(), $start->addMinutes($service->duration_minutes)->utc())->create([
                    'merchant_id' => $merchant->id,
                    'branch_id' => $branch->id,
                    'client_id' => $clients[$clientIndex]->id,
                    'service_id' => $service->id,
                    'preferred_personnel_staff_profile_id' => $slot === 0 ? $staffId : null,
                    'assigned_personnel_staff_profile_id' => $staffId,
                    'created_by' => $createdBy->id,
                ]);
            }
        }
    }

    /** @param list<string> $personnelEmails */
    private function compensation(Merchant $merchant, MerchantBranch $branch, array $personnelEmails): void
    {
        $hr = $this->users['hr@acacia.servana.test'];
        $approver = $this->users['owner@acacia.servana.test'];
        $effective = CarbonImmutable::parse('2026-01-05');

        $rule = CommissionRule::factory()->percentage(1000)->allServices()->active()->create([
            'merchant_id' => $merchant->id,
            'branch_id' => $branch->id,
            'effective_from' => $effective,
            'created_by' => $hr->id,
            'approved_by' => $approver->id,
            'change_reason' => 'Standard 10% branch commission (local UAT fixture).',
        ]);

        foreach ($personnelEmails as $i => $email) {
            $factory = PersonnelCompensationPlan::factory()->forStaffProfile($this->staff[$email]);
            $factory = $i % 2 === 0 ? $factory->salaryPlusCommission(3000000) : $factory->commissionOnly();

            $factory->active()->create([
                'commission_rule_id' => $rule->id,
                'effective_from' => $effective,
                'created_by' => $hr->id,
                'submitted_by' => $hr->id,
                'approved_by' => $approver->id,
            ]);
        }
    }

    /**
     * Write the two git-ignored files the runbook and the acceptance run read. Neither is committed:
     * `storage/app/*` is ignored. The fixture manifest holds identities and ULIDs only (no secret);
     * the MFA file holds the AUTOMATION identities' TOTP secrets and nothing else.
     */
    private function writeManifests(): void
    {
        $dir = storage_path(self::OUTPUT_DIR);
        File::ensureDirectoryExists($dir);

        $acacia = Merchant::query()->where('slug', self::MARKER_SLUG)->firstOrFail();
        $baobab = Merchant::query()->where('slug', self::SECOND_MERCHANT_SLUG)->firstOrFail();
        $branches = MerchantBranch::query()->whereIn('merchant_id', [$acacia->id, $baobab->id])->get()->keyBy('code');
        $branchId = static function (string $code) use ($branches): int {
            $branch = $branches->get($code);
            if (! $branch instanceof MerchantBranch) {
                throw new RuntimeException("The seeded {$code} branch is missing; reseed the LOCAL-UAT dataset.");
            }

            return $branch->id;
        };

        $identities = User::query()
            ->where('email', 'like', '%.servana.test')
            ->with(['merchantUsers'])
            ->orderBy('email')
            ->get()
            ->map(static function (User $user): array {
                $membership = $user->merchantUsers->first();

                return [
                    'email' => $user->email,
                    'name' => $user->name,
                    'automation' => str_starts_with($user->email, 'uat.'),
                    'role' => $user->is_platform_staff ? 'super_admin' : $membership?->role?->value,
                    'merchant_ulid' => $membership?->merchant?->ulid,
                    'staff_profile_ulid' => $membership === null ? null : StaffProfile::query()->where('merchant_user_id', $membership->id)->value('ulid'),
                ];
            })
            ->values()
            ->all();

        $subscriptionInvoiceUlid = DB::table('subscription_invoices')->where('merchant_id', $acacia->id)->orderBy('id')->value('ulid');

        $manifest = [
            'schema' => 'local-uat-001.fixture-manifest.v1',
            'generated_at' => now()->toIso8601String(),
            'merchants' => [
                'acacia' => ['ulid' => $acacia->ulid, 'name' => $acacia->name],
                'baobab' => ['ulid' => $baobab->ulid, 'name' => $baobab->name],
            ],
            'branches' => $branches->map(static fn (MerchantBranch $b): array => ['ulid' => $b->ulid, 'name' => $b->name])->all(),
            'subscription_invoice_ulid' => $subscriptionInvoiceUlid,
            'subscription_ulid' => MerchantSubscription::query()->where('merchant_id', $acacia->id)->value('ulid'),
            'westlands_client_ulid' => Client::query()->where('branch_id', $branchId('WST'))->orderBy('id')->value('ulid'),
            'kilimani_client_ulid' => Client::query()->where('branch_id', $branchId('KIL'))->orderBy('id')->value('ulid'),
            'baobab_client_ulid' => Client::query()->where('branch_id', $branchId('CBD'))->orderBy('id')->value('ulid'),
            'identities' => $identities,
        ];

        File::put($dir.'/fixture-manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n");

        $mfa = [];
        foreach (User::query()->where('email', 'like', 'uat.%.servana.test')->orderBy('email')->get() as $user) {
            $credential = MfaCredential::query()->where('user_id', $user->id)->whereNotNull('confirmed_at')->first();
            if ($credential !== null) {
                $mfa[$user->email] = (string) $credential->secret_encrypted;
            }
        }

        File::put($dir.'/automation-mfa.json', json_encode([
            'schema' => 'local-uat-001.automation-mfa.v1',
            'warning' => 'Local-only TOTP secrets for the uat.* AUTOMATION identities. Git-ignored. Never commit, paste or reuse.',
            'secrets' => $mfa,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n");

        $this->command->info('Wrote storage/app/local-uat/fixture-manifest.json and automation-mfa.json (git-ignored).');
    }
}

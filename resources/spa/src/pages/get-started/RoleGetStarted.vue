<script setup lang="ts">
import { computed, onMounted } from 'vue';
import { useRoute } from 'vue-router';
import GetStartedChecklist from '@/components/onboarding/GetStartedChecklist.vue';
import SvOperationalHero from '@/components/ui/SvOperationalHero.vue';
import SvStateBoundary from '@/components/ui/SvStateBoundary.vue';
import SvTonalPageHeader from '@/components/ui/SvTonalPageHeader.vue';
import { useRefinedExperience } from '@/design-system/experience';
import { useAuthStore } from '@/stores/authStore';
import { useBranchExperienceStore } from '@/stores/branchExperienceStore';
import { useGetStartedStore } from '@/stores/getStartedStore';
import { useHrWorkspaceStore } from '@/stores/hrWorkspaceStore';
import { useMerchantDashboardStore } from '@/stores/merchantDashboardStore';
import { useFrontOfficeWorkspaceStore } from '@/stores/frontOfficeWorkspaceStore';
import { usePersonnelExperienceStore } from '@/stores/personnelExperienceStore';
import { ROLE_IDENTITIES, type RoleIdentity } from '@/types/roles';

/**
 * Guided get-started page (Plan §27.2; Scope §3.2). Resolves the role identity
 * from route meta and the user ULID from the bootstrap, then renders the
 * resumable, dismissible checklist. Dismissal is honored (the checklist stops
 * surfacing) and can be reopened — both from this dedicated page.
 */
const route = useRoute();
const auth = useAuthStore();
const store = useGetStartedStore();
const merchantDashboard = useMerchantDashboardStore();
const branchExperience = useBranchExperienceStore();
const hrWorkspace = useHrWorkspaceStore();
const frontOfficeWorkspace = useFrontOfficeWorkspaceStore();
const personnelExperience = usePersonnelExperienceStore();
/** COR-UI08-UI12-VIS-001: Merchant Administrator, Branch, HR and Finance get the editorial journey. */
const refined = useRefinedExperience();

const identity = computed<RoleIdentity | null>(() => {
  const meta = route.meta.roleIdentity;
  return typeof meta === 'string' && ROLE_IDENTITIES.includes(meta as RoleIdentity)
    ? (meta as RoleIdentity)
    : null;
});
const userId = computed(() => auth.user?.id ?? null);
const observedCompletedIds = computed<string[]>(() => {
  if (identity.value === 'merchant_branch') {
    const readiness = branchExperience.overview?.get_started;
    if (!readiness) return [];
    const ids: string[] = [];
    if (readiness.profile_complete) ids.push('confirm-branch-profile');
    if (readiness.calendar_configured) ids.push('set-operating-hours-calendar');
    if (readiness.service_catalogue_ready) ids.push('build-service-catalogue', 'set-service-pricing-durations');
    if (readiness.day_opened) ids.push('open-branch-day');
    return ids;
  }
  if (identity.value === 'merchant_human_resource') {
    const readiness = hrWorkspace.overview?.get_started;
    if (!readiness) return [];
    const ids: string[] = [];
    if (readiness.staff_invited) ids.push('invite-staff');
    if (readiness.eligibility_configured) ids.push('set-service-eligibility');
    if (readiness.availability_configured) ids.push('set-availability');
    if (readiness.compensation_configured) ids.push('configure-compensation-models');
    if (readiness.missing_compensation_reviewed) ids.push('review-missing-compensation');
    return ids;
  }
  if (identity.value === 'merchant_front_office') {
    const readiness = frontOfficeWorkspace.overview?.get_started;
    if (!readiness) return [];
    const ids: string[] = [];
    if (readiness.client_created) ids.push('register-a-client');
    if (readiness.appointment_created) ids.push('book-an-appointment');
    if (readiness.queue_used) ids.push('start-a-walk-in', 'assign-personnel');
    if (readiness.invoice_created) ids.push('create-an-invoice');
    if (readiness.payment_recorded) ids.push('record-a-payment');
    if (readiness.receipt_available) ids.push('confirm-receipt-issuance');
    return ids;
  }
  if (identity.value === 'merchant_personnel') {
    return (personnelExperience.workspace?.clients.recent_messages ?? 0) > 0 ? ['send-approved-sms'] : [];
  }
  if (identity.value !== 'merchant_administrator') return [];
  const readiness = merchantDashboard.overview?.get_started;
  if (!readiness) return [];

  const ids: string[] = [];
  if (auth.user?.email_verified_at && readiness.setup_complete) ids.push('verify-email');
  if (readiness.subscription_selected) ids.push('choose-subscription-plan');
  if (readiness.profile_complete && readiness.logo_uploaded) ids.push('confirm-merchant-profile');
  if (readiness.first_branch_created) ids.push('create-first-branch');
  if (readiness.initial_team_active) ids.push('invite-branch-manager-hr');
  if (readiness.billing_phone_confirmed) ids.push('confirm-billing-mpesa-phone');
  if (readiness.operational_roles_active) ids.push('operational-role-readiness');
  if (readiness.daily_reports.available) ids.push('review-first-daily-reports');
  return ids;
});

onMounted(() => {
  if (identity.value === 'merchant_administrator') void merchantDashboard.fetchOverview();
  if (identity.value === 'merchant_branch') void branchExperience.fetchOverview();
  if (identity.value === 'merchant_human_resource') void hrWorkspace.fetchOverview();
  if (identity.value === 'merchant_front_office') void frontOfficeWorkspace.fetchOverview();
  if (identity.value === 'merchant_personnel') void personnelExperience.fetchWorkspace();
});

interface Orientation {
  eyebrow: string;
  title: string;
  description: string;
  context?: string;
  facts?: ReadonlyArray<{ title: string; body: string }>;
}

/**
 * Each account's orientation hero — the page's single `<h1>`.
 *
 * UI16-A11Y-001: before UI-16 only Front Office, Personnel and Audit rendered a hero, so the
 * Merchant Administrator, Branch, Human Resource and Finance Get Started pages had NO `<h1>` at all
 * (the checklist's "Get started" is a section `<h2>`). Every account now leads with one. The copy
 * restates the role boundaries the Scope already fixes; nothing here grants, computes or claims a
 * completion — progress still comes only from the server-observed checklist below.
 */
const ORIENTATION = computed<Partial<Record<RoleIdentity, Orientation>>>(() => ({
  merchant_administrator: {
    eyebrow: 'Owner setup journey',
    title: 'Set up the business your teams will run',
    description: 'Confirm your plan, business identity and first branch, then bring in the Branch Manager, HR, Finance and Front Office owners. You oversee the business; they operate it.',
    facts: [
      { title: 'Owner decisions', body: 'Plan, business profile, branches and owner approvals.' },
      { title: 'Operational ownership', body: 'Services, pricing, staff assignment and payment checks belong to operational roles.' },
      { title: 'Observed, not ticked', body: 'Owner steps complete only when Servana observes them.' },
    ],
  },
  merchant_branch: {
    eyebrow: 'Branch opening journey',
    title: 'Prepare your branch to open its day',
    description: 'Confirm the branch profile, publish operating hours and the service catalogue, then open the branch day. Finance stays the checker for money; HR owns staff assignment.',
    context: branchExperience.overview?.branch.name,
    facts: [
      { title: 'Your branch only', body: 'Every step acts on the branch you are assigned to.' },
      { title: 'Services and hours', body: 'Catalogue, pricing, durations and the operating calendar.' },
      { title: 'Day operations', body: 'Open the day, follow the queue and submit cash-up for Finance.' },
    ],
  },
  merchant_human_resource: {
    eyebrow: 'Workforce readiness journey',
    title: 'Make your team ready to serve',
    description: 'Invite operational staff, set service eligibility and availability, and configure compensation terms for your branch. You prepare payouts; Finance verifies and records them.',
    context: hrWorkspace.overview?.branch.name,
    facts: [
      { title: 'Same-branch scope', body: 'People, eligibility and terms for your branch only.' },
      { title: 'Maker, not checker', body: 'Payout runs you submit are verified by Finance.' },
      { title: 'Observed progress', body: 'Readiness steps complete when Servana observes them.' },
    ],
  },
  merchant_finance: {
    eyebrow: 'Checker orientation',
    title: 'Own the controls that make money trustworthy',
    description: 'Enroll in MFA, then learn validation, duplicate-reference, cash-up, period-lock and payout separation. Recorded money stays pending until a checker validates it; receipts follow that server decision.',
    facts: [
      { title: 'Step-up for control actions', body: 'Sensitive decisions ask for a fresh MFA check.' },
      { title: 'Maker and checker apart', body: 'You never validate money you recorded yourself.' },
      { title: 'Server-owned totals', body: 'Every amount and status comes from Servana, never the browser.' },
    ],
  },
  merchant_front_office: {
    eyebrow: 'Guided branch journey',
    title: 'From welcome to verified receipt',
    description: 'Follow the real service-desk sequence. Server-owned completion moves from client registration through service and invoice creation to a recorded payment that Finance must validate before the original receipt appears.',
  },
  merchant_personnel: {
    eyebrow: 'Your private orientation',
    title: 'Know your work, pay and privacy boundaries',
    description: 'Learn the path from own assignment to completed service, understand when commission is actually earned, and keep served-client follow-up inside Servana.',
    context: personnelExperience.workspace?.staff.branch?.name,
    facts: [
      { title: 'Work states', body: 'Queue, appointment and session facts remain separate.' },
      { title: 'Pay states', body: 'Pending, earned, approved, paid and reversed are not interchangeable.' },
      { title: 'Private follow-up', body: 'Masked contacts, consent checks and branch-billed SMS.' },
    ],
  },
  // Phase UI-15: the Audit orientation. Teaching only — no item grants export or unmask access.
  merchant_audit: {
    eyebrow: 'Your Audit orientation',
    title: 'Review the evidence without changing it',
    description: 'Learn your branch scope, how to search and read masked events, how a review flag works, what an export contains and how the audit chain is checked.',
    context: 'Read-only reviewer',
    facts: [
      { title: 'Immutable record', body: 'Events are appended once and never edited or deleted.' },
      { title: 'Two different facts', body: 'Event severity never changes; review status is your team’s progress.' },
      { title: 'Masked and scoped', body: 'Only your branch, with protected values masked by the server.' },
    ],
  },
}));

const orientation = computed<Orientation | null>(() => (identity.value ? ORIENTATION.value[identity.value] ?? null : null));

const dismissed = computed(() =>
  identity.value && userId.value
    ? store.isDismissed(userId.value, identity.value)
    : false,
);

function onDismiss(): void {
  if (identity.value && userId.value) store.dismiss(userId.value, identity.value);
}
function reopen(): void {
  if (identity.value && userId.value) store.reopen(userId.value, identity.value);
}
</script>

<template>
  <div :class="refined ? 'sv-r-page' : 'mx-auto max-w-5xl'">
    <template v-if="identity && userId">
      <!--
        COR-UI08-UI12-VIS-001: the refined accounts open on an editorial header that owns the h1,
        then their three role principles as numbered cards — the boundaries the Scope already fixes,
        stated before the first step rather than inside a dark slab.
      -->
      <template v-if="refined && orientation">
        <SvTonalPageHeader
          :eyebrow="orientation.eyebrow"
          :title="orientation.title"
          :description="orientation.description"
          :context="orientation.context"
        />
        <ul
          v-if="orientation.facts"
          class="sv-r-grid sv-r-cols-3 mb-6"
          aria-label="How this role works"
        >
          <li
            v-for="(fact, index) in orientation.facts"
            :key="fact.title"
            class="sv-r-record flex items-start gap-4"
          >
            <span
              aria-hidden="true"
              class="sv-r-icon sv-r-icon--lg sv-r-tone-sun font-display text-base font-extrabold"
            >0{{ index + 1 }}</span>
            <span class="min-w-0">
              <strong class="block font-display text-base font-extrabold text-sv-text-heading">{{ fact.title }}</strong>
              <span class="sv-r-lede mt-1 block">{{ fact.body }}</span>
            </span>
          </li>
        </ul>
      </template>
      <SvOperationalHero
        v-else-if="orientation"
        class="mb-5"
        :eyebrow="orientation.eyebrow"
        :title="orientation.title"
        :description="orientation.description"
        :context="orientation.context"
      >
        <template
          v-if="orientation.facts"
          #default
        >
          <div class="grid gap-3 text-sm md:grid-cols-3">
            <div
              v-for="fact in orientation.facts"
              :key="fact.title"
              class="rounded-control border border-white/10 bg-white/10 p-3"
            >
              <strong class="block">{{ fact.title }}</strong><span class="text-white/70">{{ fact.body }}</span>
            </div>
          </div>
        </template>
      </SvOperationalHero>
      <div
        v-if="dismissed"
        :class="refined ? 'sv-r-panel sv-r-panel--tonal sv-r-tone-sun mx-auto max-w-xl text-center' : 'rounded-card border border-border bg-surface p-6 text-center'"
      >
        <h2 class="font-display text-lg font-bold text-heading">
          Get-started is hidden
        </h2>
        <p class="mt-1 text-sm text-text-muted">
          You dismissed the get-started checklist. You can reopen it anytime.
        </p>
        <button
          type="button"
          class="mt-4 inline-flex min-h-[44px] items-center rounded-control bg-primary px-4 py-2 text-sm font-semibold text-brand-deep hover:bg-orange-400 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-2"
          data-testid="reopen-get-started"
          @click="reopen"
        >
          Reopen get-started
        </button>
      </div>
      <GetStartedChecklist
        v-else
        :identity="identity"
        :user-id="userId"
        :observed-completed-ids="observedCompletedIds"
        @dismiss="onDismiss"
      />
    </template>
    <SvStateBoundary
      v-else
      state="error"
      error-message="We couldn't load your get-started checklist. Please sign in again."
    />
  </div>
</template>

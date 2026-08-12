<script setup lang="ts">
import { computed, onMounted } from 'vue';
import { useRoute } from 'vue-router';
import GetStartedChecklist from '@/components/onboarding/GetStartedChecklist.vue';
import SvStateBoundary from '@/components/ui/SvStateBoundary.vue';
import { useAuthStore } from '@/stores/authStore';
import { useGetStartedStore } from '@/stores/getStartedStore';
import { useMerchantDashboardStore } from '@/stores/merchantDashboardStore';
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

const identity = computed<RoleIdentity | null>(() => {
  const meta = route.meta.roleIdentity;
  return typeof meta === 'string' && ROLE_IDENTITIES.includes(meta as RoleIdentity)
    ? (meta as RoleIdentity)
    : null;
});
const userId = computed(() => auth.user?.id ?? null);
const observedCompletedIds = computed<string[]>(() => {
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
});

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
  <div class="mx-auto max-w-3xl">
    <template v-if="identity && userId">
      <div
        v-if="dismissed"
        class="rounded-card border border-border bg-surface p-6 text-center"
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

<script setup lang="ts">
/**
 * Merchant Detail and Governance — Super Administrator contract page §5.4.12 (Phase UI-08).
 *
 * The page that gives the merchant governance record an ADDRESS. In the consolidated screen this
 * replaces, the detail was a pane rendered from a row the user had clicked, so it could not be
 * linked, bookmarked, reloaded or shared — the defect this route exists to close.
 *
 * ## Deep-link safety
 *
 * This page never reads a merchant left over from the directory. It resolves `merchantUlid` from
 * the route on mount AND on every param change, and requests the record itself. A ULID that is
 * unknown, belongs to nothing the caller may read, or is simply wrong renders one non-enumerating
 * state — the page never reveals whether the record exists. There is deliberately no client-side
 * ULID pattern check: the server's 404 is the authority, and a client rule that disagreed with it
 * would either block a valid record or imply something about an invalid one.
 *
 * ## Boundaries
 *
 * Governance changes OPERATIONAL status only, through the shared `MerchantGovernancePanel`, which
 * the retiring consolidated screen also composes. No impersonation, no merchant setup completion,
 * no branch or staff creation, no invoice, payment, receipt or queue action, and no billing
 * recovery: a billing suspension is cleared by the billing lifecycle, never by reactivation here.
 *
 * Routed in Increment 7B.
 */
import { computed, onMounted, watch } from 'vue';
import { useRoute } from 'vue-router';
import SvAlert from '@/components/ui/SvAlert.vue';
import SvBreadcrumbs from '@/components/ui/SvBreadcrumbs.vue';
import SvErrorState from '@/components/ui/SvErrorState.vue';
import SvLink from '@/components/ui/SvLink.vue';
import SvPageHeader from '@/components/ui/SvPageHeader.vue';
import SvPermissionState from '@/components/ui/SvPermissionState.vue';
import SvSkeleton from '@/components/ui/SvSkeleton.vue';
import MerchantGovernancePanel from '@/components/platform/merchants/MerchantGovernancePanel.vue';
import { useCan } from '@/composables/useCan';
import { usePlatformMerchantStore } from '@/stores/platformMerchantStore';
import {
  MERCHANT_DIRECTORY_ROUTE_NAME,
  PLATFORM_AUDIT_ROUTE_NAME,
} from '@/components/platform/merchants/merchantRoutes';

const route = useRoute();
const store = usePlatformMerchantStore();
const { can } = useCan();

const canView = computed(() => can('platform.merchant.view'));

/** The param, normalised to a single trimmed string. A repeated param yields its first value. */
const merchantUlid = computed(() => {
  const raw = route.params.merchantUlid;
  const value = Array.isArray(raw) ? raw[0] : raw;
  return typeof value === 'string' ? value.trim() : '';
});

const merchant = computed(() => store.selected);
const outcome = computed(() => store.detailOutcome);

/**
 * A refused record and an unknown one render the SAME message. Distinguishing them here would let
 * anyone with a URL bar enumerate which merchants exist on the platform.
 */
const isUnavailable = computed(() => outcome.value === 'not_found' || outcome.value === 'forbidden');

const breadcrumbs = computed(() => [
  { label: 'Merchants', to: { name: MERCHANT_DIRECTORY_ROUTE_NAME } },
  { label: merchant.value?.name ?? 'Merchant' },
]);

onMounted(load);
watch(merchantUlid, load);

async function load(): Promise<void> {
  if (!canView.value) return;
  if (merchantUlid.value === '') {
    // No identifier at all: nothing to request, and nothing to say about what might exist.
    store.detailOutcome = 'not_found';
    return;
  }
  await store.loadMerchant(merchantUlid.value);
}
</script>

<template>
  <div
    class="mx-auto w-full max-w-4xl"
    data-testid="platform-merchant-detail-screen"
  >
    <SvPageHeader
      :title="merchant?.name ?? 'Merchant governance'"
      eyebrow="Merchants"
      description="Govern this merchant’s platform lifecycle. Operational status and billing status are separate, and nothing here operates the merchant’s own business."
    >
      <template #breadcrumbs>
        <SvBreadcrumbs :items="breadcrumbs" />
      </template>
    </SvPageHeader>

    <SvPermissionState v-if="!canView" />

    <template v-else>
      <div
        v-if="outcome === 'loading'"
        data-testid="merchant-detail-loading"
      >
        <SvSkeleton
          shape="text"
          :lines="4"
          label="Loading merchant"
        />
      </div>

      <SvPermissionState
        v-else-if="isUnavailable"
        title="This merchant isn’t available to you"
        message="We can’t show a merchant for that address."
        guidance="Return to the merchant directory and open a merchant from there."
        data-testid="merchant-detail-unavailable"
      />

      <SvErrorState
        v-else-if="outcome === 'error'"
        message="We couldn’t load this merchant."
        data-testid="merchant-detail-error"
        @retry="load"
      />

      <template v-else-if="merchant">
        <MerchantGovernancePanel
          :merchant="merchant"
          heading-level="h2"
        />

        <!--
          The contract's evidence tabs. Each one that has no platform read is NAMED, with the reason,
          rather than rendered as an empty tab — an empty "Invoices" tab asserts that this merchant
          has no invoices, which is a claim this page cannot make.
        -->
        <SvAlert
          severity="info"
          title="Evidence not yet available on this page"
          class="mt-8"
          data-testid="merchant-detail-unavailable-evidence"
        >
          <ul class="list-disc space-y-1 pl-5">
            <li>
              A per-merchant governance timeline is not available: the platform audit read cannot be
              scoped to one merchant, so any list here would be a partial one presented as complete.
              Platform governance events are searchable on the platform audit page.
            </li>
            <li>
              Subscription invoices, Wallet payment attempts and billing-recovery status are not
              read per merchant here. Platform-wide subscription operations cover them.
            </li>
            <li>
              Branches, staff overview and referral or qualification facts have no platform-scoped
              read. They are merchant-owned data and are not exposed to platform governance.
            </li>
            <li>
              Notes exist only as the mandatory reason recorded with a governance action, and
              evidence attachment is not implemented.
            </li>
          </ul>
        </SvAlert>

        <div class="mt-6 flex flex-wrap items-center gap-6">
          <SvLink
            v-if="can('platform.audit.view')"
            :to="{ name: PLATFORM_AUDIT_ROUTE_NAME }"
            data-testid="merchant-detail-audit-link"
          >
            Search platform governance events
          </SvLink>
          <SvLink
            :to="{ name: MERCHANT_DIRECTORY_ROUTE_NAME }"
            variant="subtle"
            data-testid="merchant-detail-back-link"
          >
            Back to the merchant directory
          </SvLink>
        </div>
      </template>
    </template>
  </div>
</template>

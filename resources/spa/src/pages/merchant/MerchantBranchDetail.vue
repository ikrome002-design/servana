<script setup lang="ts">
import { computed, onMounted } from 'vue';
import { useRoute } from 'vue-router';
import SvAlert from '@/components/ui/SvAlert.vue';
import SvCard from '@/components/ui/SvCard.vue';
import SvPageHeader from '@/components/ui/SvPageHeader.vue';
import SvStateBoundary from '@/components/ui/SvStateBoundary.vue';
import { useBranchStore } from '@/stores/branchStore';

const route = useRoute();
const branches = useBranchStore();
const branchUlid = computed(() => String(route.params.branchUlid ?? ''));
const state = computed(() => branches.loading ? 'loading' : branches.activeBranch ? 'success' : 'empty');

onMounted(() => { void branches.fetchBranch(branchUlid.value); });
</script>

<template>
  <section class="mx-auto max-w-5xl" data-testid="merchant-branch-detail">
    <nav aria-label="Breadcrumb" class="mb-3 text-sm text-text-muted">
      <RouterLink class="sv-focus-ring rounded-control underline" :to="{ name: 'merchant.branches' }">Branches</RouterLink>
      <span aria-hidden="true"> / </span><span>Branch detail</span>
    </nav>
    <SvPageHeader
      :title="branches.activeBranch?.name ?? 'Branch detail'"
      eyebrow="Merchant oversight"
      description="Read-only owner context for this branch. Branch Manager, Human Resource, Front Office and Finance retain their operational responsibilities."
    />
    <SvStateBoundary class="mt-6" :state="state" empty-message="This branch could not be loaded." @retry="branches.fetchBranch(branchUlid)">
      <template v-if="branches.activeBranch">
        <div class="grid gap-4 md:grid-cols-2">
          <SvCard as="section">
            <h2 class="font-display text-lg font-bold text-heading">Branch profile</h2>
            <dl class="mt-4 grid grid-cols-[auto_minmax(0,1fr)] gap-x-4 gap-y-3 text-sm">
              <dt class="text-text-muted">Code</dt><dd class="text-text">{{ branches.activeBranch.code }}</dd>
              <dt class="text-text-muted">Status</dt><dd class="text-text">{{ branches.activeBranch.status }}</dd>
              <dt class="text-text-muted">Town</dt><dd class="text-text">{{ branches.activeBranch.town ?? '—' }}</dd>
              <dt class="text-text-muted">Address</dt><dd class="text-text">{{ branches.activeBranch.address ?? '—' }}</dd>
              <dt class="text-text-muted">Contact</dt><dd class="text-text">{{ branches.activeBranch.phone ?? branches.activeBranch.email ?? '—' }}</dd>
            </dl>
          </SvCard>
          <SvCard as="section">
            <h2 class="font-display text-lg font-bold text-heading">Responsibility boundaries</h2>
            <ul class="mt-4 space-y-3 text-sm text-text">
              <li><strong>Branch Manager:</strong> operating hours, calendar, services, pricing and branch day.</li>
              <li><strong>Human Resource:</strong> operational staff, eligibility, availability and compensation setup.</li>
              <li><strong>Front Office / Finance:</strong> clients, invoices, payment recording/validation, receipts and cash-up.</li>
            </ul>
          </SvCard>
        </div>
        <SvAlert severity="info" title="Branch performance is gated" class="mt-4">
          Revenue, service/staff performance and daily-report archive data remain unavailable behind External Gate W. This page does not fabricate empty metrics.
        </SvAlert>
      </template>
    </SvStateBoundary>
  </section>
</template>

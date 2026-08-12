<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue';
import SvDialog from '@/components/ui/SvDialog.vue';
import SvButton from '@/components/ui/SvButton.vue';
import SvCard from '@/components/ui/SvCard.vue';
import SvPageHeader from '@/components/ui/SvPageHeader.vue';
import SvSelect from '@/components/ui/SvSelect.vue';
import SvStateBoundary from '@/components/ui/SvStateBoundary.vue';
import SvTextInput from '@/components/ui/SvTextInput.vue';
import { useBranchStore } from '@/stores/branchStore';
import { useMerchantStaffOverviewStore, type MerchantStaffOverviewRow } from '@/stores/merchantStaffOverviewStore';
import { useNotificationStore } from '@/stores/notificationStore';
import { useStaffStore } from '@/stores/staffStore';

const directory = useMerchantStaffOverviewStore();
const staff = useStaffStore();
const branches = useBranchStore();
const notifications = useNotificationStore();
const inviteEmail = ref('');
const inviteRole = ref('branch_manager');
const inviteBranch = ref('');
const inviting = ref(false);
const expanded = ref<string | null>(null);
const pendingLifecycle = ref<{ row: MerchantStaffOverviewRow; action: 'suspend' | 'activate' | 'deactivate' } | null>(null);
const lifecycleReason = ref('');
const filters = reactive({ search: '', branch_ulid: '', role: '', status: '', sort: '-created_at', page: 1 });

const state = computed(() => directory.loading ? 'loading' : directory.error ? 'error' : directory.rows.length ? 'success' : 'empty');
const roleOptions = [
  { value: 'branch_manager', label: 'Branch Manager' },
  { value: 'hr', label: 'Human Resource' },
];
const branchOptions = computed(() => branches.branches.map((branch) => ({ value: branch.id, label: `${branch.name} (${branch.code})` })));
const directoryRoleOptions = [
  { value: '', label: 'All account types' },
  { value: 'merchant_admin', label: 'Merchant Administrator' },
  { value: 'branch_manager', label: 'Branch Manager' },
  { value: 'hr', label: 'Human Resource' },
  { value: 'finance', label: 'Finance' },
  { value: 'front_office', label: 'Front Office' },
  { value: 'personnel', label: 'Personnel' },
  { value: 'audit', label: 'Audit' },
];
const statusOptions = [{ value: '', label: 'All statuses' }, ...['active', 'invited', 'suspended', 'deactivated'].map((value) => ({ value, label: value }))];
const sortOptions = [
  { value: '-created_at', label: 'Newest membership' },
  { value: 'created_at', label: 'Oldest membership' },
  { value: '-activated_at', label: 'Recently activated' },
  { value: 'activated_at', label: 'Earliest activation' },
];

function savedFilters(): void {
  try { sessionStorage.setItem('servana.ui09.staff.filters', JSON.stringify(filters)); } catch { /* Session persistence is optional. */ }
}
async function applyFilters(page = 1): Promise<void> {
  filters.page = page;
  savedFilters();
  await directory.fetchRows(filters);
}
function clearFilters(): void {
  Object.assign(filters, { search: '', branch_ulid: '', role: '', status: '', sort: '-created_at', page: 1 });
  void applyFilters();
}

async function invite(): Promise<void> {
  if (!inviteEmail.value || !inviteBranch.value || inviting.value) return;
  inviting.value = true;
  try {
    await staff.invite({ email: inviteEmail.value, branch_id: inviteBranch.value, role: inviteRole.value });
    inviteEmail.value = '';
    notifications.addToast({ type: 'success', message: 'Invitation sent with Magic Link instructions.' });
    await directory.fetchRows();
  } finally {
    inviting.value = false;
  }
}

function confirmStatus(row: MerchantStaffOverviewRow, action: 'suspend' | 'activate' | 'deactivate'): void {
  pendingLifecycle.value = { row, action };
  lifecycleReason.value = '';
}

async function executeStatus(): Promise<void> {
  const pending = pendingLifecycle.value;
  if (!pending) return;
  await directory.lifecycle(pending.row, pending.action, lifecycleReason.value.trim() || undefined);
  notifications.addToast({ type: 'success', message: `${pending.row.display_name}'s access was updated.` });
  pendingLifecycle.value = null;
  await applyFilters(filters.page);
}

onMounted(() => {
  try { Object.assign(filters, JSON.parse(sessionStorage.getItem('servana.ui09.staff.filters') ?? '{}')); } catch { /* Use safe defaults. */ }
  void Promise.all([applyFilters(filters.page), branches.fetchBranches(), staff.fetchInvitations()]);
});
</script>

<template>
  <section class="mx-auto max-w-6xl" data-testid="merchant-staff-overview">
    <SvPageHeader title="Staff overview and lifecycle" eyebrow="Merchant" description="Tenant-wide access oversight. Human Resource retains operational staff setup, eligibility, availability and compensation ownership." />

    <SvCard as="section" class="mt-6">
      <h2 class="font-display text-lg font-bold text-heading">Invite an initial branch owner</h2>
      <p class="mt-1 text-sm text-text-muted">This owner flow permits Branch Manager and Human Resource invitations only. Operational roles are invited by Human Resource.</p>
      <form class="mt-4 grid gap-4 md:grid-cols-3" @submit.prevent="invite">
        <SvTextInput id="owner-invite-email" v-model="inviteEmail" label="Email" type="email" required />
        <SvSelect id="owner-invite-role" v-model="inviteRole" label="Account type" :options="roleOptions" />
        <SvSelect id="owner-invite-branch" v-model="inviteBranch" label="Branch" placeholder="Select a branch" :options="branchOptions" required />
        <div class="md:col-span-3"><SvButton type="submit" :loading="inviting">Send invitation</SvButton></div>
      </form>
    </SvCard>

    <div class="mt-6 flex items-end justify-between gap-3">
      <div><h2 class="font-display text-lg font-bold text-heading">Merchant users</h2><p class="text-sm text-text-muted">{{ directory.total }} membership records. Phone numbers and client data are not included.</p></div>
    </div>
    <form class="mt-4 grid gap-3 rounded-card border border-border bg-surface p-4 md:grid-cols-2 lg:grid-cols-5" aria-label="Filter merchant users" @submit.prevent="applyFilters()">
      <SvTextInput id="staff-search" v-model="filters.search" label="Search name or email" />
      <SvSelect id="staff-branch-filter" v-model="filters.branch_ulid" label="Branch" :options="[{ value: '', label: 'All branches' }, ...branchOptions]" />
      <SvSelect id="staff-role-filter" v-model="filters.role" label="Account type" :options="directoryRoleOptions" />
      <SvSelect id="staff-status-filter" v-model="filters.status" label="Status" :options="statusOptions" />
      <SvSelect id="staff-sort" v-model="filters.sort" label="Sort" :options="sortOptions" />
      <div class="flex flex-wrap gap-2 lg:col-span-5"><SvButton type="submit">Apply filters</SvButton><SvButton type="button" variant="secondary" @click="clearFilters">Clear filters</SvButton></div>
    </form>
    <SvStateBoundary class="mt-4" :state="state" :error-message="directory.error ?? undefined" empty-message="No merchant users are available." @retry="directory.fetchRows()">
      <ul class="grid gap-4 md:grid-cols-2" aria-label="Merchant staff lifecycle directory">
        <li v-for="row in directory.rows" :key="row.id">
          <SvCard as="article" class="h-full">
            <div class="flex flex-wrap items-start justify-between gap-3">
              <div class="min-w-0"><h3 class="break-words font-display font-bold text-heading">{{ row.display_name }}</h3><p class="break-all text-sm text-text-muted">{{ row.email }}</p></div>
              <span class="rounded-full bg-surface-alt px-2 py-1 text-xs font-semibold text-text">{{ row.status }}</span>
            </div>
            <dl class="mt-4 grid gap-2 text-sm">
              <div><dt class="inline text-text-muted">Account: </dt><dd class="inline text-text">{{ row.role.replaceAll('_', ' ') }}</dd></div>
              <div><dt class="inline text-text-muted">Branches: </dt><dd class="inline text-text">{{ row.branches.map((branch) => branch.name).join(', ') || 'Merchant-wide / not assigned' }}</dd></div>
              <div><dt class="inline text-text-muted">Last sign-in: </dt><dd class="inline text-text">{{ row.last_login_at?.slice(0, 10) ?? 'Never' }}</dd></div>
              <div><dt class="inline text-text-muted">Active sessions: </dt><dd class="inline text-text">{{ row.active_session_count }}</dd></div>
            </dl>
            <div v-if="row.can.manage_lifecycle" class="mt-4 flex flex-wrap gap-2">
              <SvButton v-if="row.status === 'active'" size="sm" variant="secondary" :loading="directory.mutating === row.id" @click="confirmStatus(row, 'suspend')">Suspend access</SvButton>
              <SvButton v-if="row.status === 'suspended'" size="sm" :loading="directory.mutating === row.id" @click="confirmStatus(row, 'activate')">Reactivate</SvButton>
              <SvButton v-if="row.status !== 'deactivated'" size="sm" variant="secondary" :loading="directory.mutating === row.id" @click="confirmStatus(row, 'deactivate')">Deactivate</SvButton>
            </div>
            <button type="button" class="sv-focus-ring mt-4 min-h-sv-touch rounded-control text-sm font-semibold text-heading underline" @click="expanded = expanded === row.id ? null : row.id">{{ expanded === row.id ? 'Hide history' : 'View assignment and status history' }}</button>
            <div v-if="expanded === row.id" class="mt-3 rounded-control bg-surface-alt p-3 text-sm">
              <h4 class="font-semibold text-heading">Branch assignments</h4>
              <ul class="mt-1 space-y-1 text-text-muted"><li v-for="entry in row.assignment_history" :key="`${entry.branch}-${entry.assigned_at}`">{{ entry.branch }} · {{ entry.status }}</li><li v-if="row.assignment_history.length === 0">No assignment history.</li></ul>
              <h4 class="mt-3 font-semibold text-heading">Status history</h4>
              <ul class="mt-1 space-y-1 text-text-muted"><li v-for="entry in row.status_history" :key="`${entry.field}-${entry.changed_at}`">{{ entry.field.replaceAll('_', ' ') }} · {{ entry.changed_at?.slice(0, 10) ?? 'Recorded' }}</li><li v-if="row.status_history.length === 0">No lifecycle history.</li></ul>
            </div>
          </SvCard>
        </li>
      </ul>
    </SvStateBoundary>
    <nav v-if="directory.lastPage > 1" class="mt-4 flex items-center justify-between" aria-label="Staff directory pagination"><SvButton variant="secondary" :disabled="directory.page <= 1" @click="applyFilters(directory.page - 1)">Previous</SvButton><span class="text-sm text-text-muted">Page {{ directory.page }} of {{ directory.lastPage }}</span><SvButton variant="secondary" :disabled="directory.page >= directory.lastPage" @click="applyFilters(directory.page + 1)">Next</SvButton></nav>

    <SvDialog :open="pendingLifecycle !== null" title="Confirm access change" description="Review the security impact before continuing." @close="pendingLifecycle = null">
      <div v-if="pendingLifecycle" class="space-y-4">
        <p class="text-sm text-text">{{ pendingLifecycle.action }} access for <strong>{{ pendingLifecycle.row.display_name }}</strong>. {{ pendingLifecycle.row.active_session_count }} active account-context session(s), unused Magic Links, and pending invitations will be revoked where applicable. Historical records are preserved.</p>
        <SvTextInput id="lifecycle-reason" v-model="lifecycleReason" label="Reason (recommended)" />
        <div class="flex justify-end gap-2"><SvButton variant="secondary" @click="pendingLifecycle = null">Cancel</SvButton><SvButton :loading="directory.mutating === pendingLifecycle.row.id" @click="executeStatus">Confirm {{ pendingLifecycle.action }}</SvButton></div>
      </div>
    </SvDialog>
  </section>
</template>

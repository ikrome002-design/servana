<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue';
import SvDialog from '@/components/ui/SvDialog.vue';
import SvButton from '@/components/ui/SvButton.vue';
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
  <!--
    COR-UI08-UI12-VIS-001 — tenant-wide staff lifecycle oversight. The directory owns the working
    column as smart people ROWS (initials, identity, account type, branches, last sign-in, sessions,
    a status chip, the audited lifecycle actions and an expandable history), under a compact filter
    band; the rail holds the owner's narrow invitation flow and the boundary that Human Resource
    owns operational staff — so nothing here implies HR access.
  -->
  <section
    class="sv-r-page"
    data-testid="merchant-staff-overview"
  >
    <SvPageHeader
      title="Staff overview and lifecycle"
      eyebrow="Merchant"
      description="Tenant-wide access oversight. Human Resource retains operational staff setup, eligibility, availability and compensation ownership."
    />

    <div class="sv-r-grid sv-r-split">
      <div class="sv-r-stack min-w-0">
        <div class="flex flex-wrap items-end justify-between gap-3">
          <div>
            <p class="sv-r-eyebrow sv-r-eyebrow--dot sv-r-tone-teal">
              Directory
            </p>
            <h2 class="sv-r-title mt-1">
              Merchant users
            </h2><p class="sv-r-muted">
              {{ directory.total }} membership records. Phone numbers and client data are not included.
            </p>
          </div>
        </div>
        <form
          class="sv-r-panel sv-r-panel--sunken grid gap-3 md:grid-cols-2 lg:grid-cols-5"
          aria-label="Filter merchant users"
          @submit.prevent="applyFilters()"
        >
          <SvTextInput
            id="staff-search"
            v-model="filters.search"
            label="Search name or email"
          />
          <SvSelect
            id="staff-branch-filter"
            v-model="filters.branch_ulid"
            label="Branch"
            :options="[{ value: '', label: 'All branches' }, ...branchOptions]"
          />
          <SvSelect
            id="staff-role-filter"
            v-model="filters.role"
            label="Account type"
            :options="directoryRoleOptions"
          />
          <SvSelect
            id="staff-status-filter"
            v-model="filters.status"
            label="Status"
            :options="statusOptions"
          />
          <SvSelect
            id="staff-sort"
            v-model="filters.sort"
            label="Sort"
            :options="sortOptions"
          />
          <div class="flex flex-wrap gap-2 lg:col-span-5">
            <SvButton type="submit">
              Apply filters
            </SvButton><SvButton
              type="button"
              variant="secondary"
              @click="clearFilters"
            >
              Clear filters
            </SvButton>
          </div>
        </form>
        <SvStateBoundary
          :state="state"
          :error-message="directory.error ?? undefined"
          empty-message="No merchant users are available."
          @retry="directory.fetchRows()"
        >
          <ul
            class="flex flex-col gap-3"
            aria-label="Merchant staff lifecycle directory"
          >
            <li
              v-for="row in directory.rows"
              :key="row.id"
            >
              <article class="sv-r-record">
                <div class="flex flex-wrap items-start gap-4">
                  <span
                    aria-hidden="true"
                    class="inline-flex h-12 w-12 shrink-0 items-center justify-center rounded-full bg-sv-surface-warm font-display text-sm font-extrabold text-sv-text-heading"
                  >{{ row.display_name.slice(0, 2).toUpperCase() }}</span>
                  <div class="min-w-0 flex-1">
                    <div class="flex flex-wrap items-center gap-2">
                      <h3 class="break-words font-display font-bold text-heading">
                        {{ row.display_name }}
                      </h3>
                      <span
                        class="sv-r-chip sv-r-chip--tone sv-r-chip--dot"
                        :class="row.status === 'active' ? 'sv-r-tone-green' : row.status === 'suspended' ? 'sv-r-tone-sun' : 'sv-r-tone-neutral'"
                      >{{ row.status }}</span>
                    </div>
                    <p class="break-all text-sm text-text-muted">
                      {{ row.email }}
                    </p>
                  </div>
                </div>
                <dl class="sv-r-strip mt-4">
                  <div>
                    <dt>Account<span class="sr-only">: </span></dt>
                    <dd class="capitalize">
                      {{ row.role.replaceAll('_', ' ') }}
                    </dd>
                  </div>
                  <div>
                    <dt>Branches<span class="sr-only">: </span></dt>
                    <dd>{{ row.branches.map((branch) => branch.name).join(', ') || 'Merchant-wide / not assigned' }}</dd>
                  </div>
                  <div>
                    <dt>Last sign-in<span class="sr-only">: </span></dt>
                    <dd>{{ row.last_login_at?.slice(0, 10) ?? 'Never' }}</dd>
                  </div>
                  <div>
                    <dt>Active sessions<span class="sr-only">: </span></dt>
                    <dd>{{ row.active_session_count }}</dd>
                  </div>
                </dl>
                <div class="mt-4 flex flex-wrap items-center gap-2">
                  <template v-if="row.can.manage_lifecycle">
                    <SvButton
                      v-if="row.status === 'active'"
                      size="sm"
                      variant="secondary"
                      :loading="directory.mutating === row.id"
                      @click="confirmStatus(row, 'suspend')"
                    >
                      Suspend access
                    </SvButton>
                    <SvButton
                      v-if="row.status === 'suspended'"
                      size="sm"
                      :loading="directory.mutating === row.id"
                      @click="confirmStatus(row, 'activate')"
                    >
                      Reactivate
                    </SvButton>
                    <SvButton
                      v-if="row.status !== 'deactivated'"
                      size="sm"
                      variant="secondary"
                      :loading="directory.mutating === row.id"
                      @click="confirmStatus(row, 'deactivate')"
                    >
                      Deactivate
                    </SvButton>
                  </template>
                  <button
                    type="button"
                    class="sv-r-link sv-focus-ring ml-auto rounded-control"
                    :aria-expanded="expanded === row.id"
                    @click="expanded = expanded === row.id ? null : row.id"
                  >
                    {{ expanded === row.id ? 'Hide history' : 'View assignment and status history' }}
                  </button>
                </div>
                <div
                  v-if="expanded === row.id"
                  class="mt-3 grid gap-4 rounded-card bg-sv-surface-subtle p-4 text-sm md:grid-cols-2"
                >
                  <div>
                    <h4 class="font-semibold text-heading">
                      Branch assignments
                    </h4>
                    <ul class="mt-1 space-y-1 text-text-muted">
                      <li
                        v-for="entry in row.assignment_history"
                        :key="`${entry.branch}-${entry.assigned_at}`"
                      >
                        {{ entry.branch }} · {{ entry.status }}
                      </li><li v-if="row.assignment_history.length === 0">
                        No assignment history.
                      </li>
                    </ul>
                  </div>
                  <div>
                    <h4 class="font-semibold text-heading">
                      Status history
                    </h4>
                    <ul class="mt-1 space-y-1 text-text-muted">
                      <li
                        v-for="entry in row.status_history"
                        :key="`${entry.field}-${entry.changed_at}`"
                      >
                        {{ entry.field.replaceAll('_', ' ') }} · {{ entry.changed_at?.slice(0, 10) ?? 'Recorded' }}
                      </li><li v-if="row.status_history.length === 0">
                        No lifecycle history.
                      </li>
                    </ul>
                  </div>
                </div>
              </article>
            </li>
          </ul>
        </SvStateBoundary>
        <nav
          v-if="directory.lastPage > 1"
          class="flex items-center justify-between"
          aria-label="Staff directory pagination"
        >
          <SvButton
            variant="secondary"
            :disabled="directory.page <= 1"
            @click="applyFilters(directory.page - 1)"
          >
            Previous
          </SvButton><span class="text-sm text-text-muted">Page {{ directory.page }} of {{ directory.lastPage }}</span><SvButton
            variant="secondary"
            :disabled="directory.page >= directory.lastPage"
            @click="applyFilters(directory.page + 1)"
          >
            Next
          </SvButton>
        </nav>
      </div>

      <aside
        class="sv-r-stack-sm sv-r-sticky"
        aria-label="Owner invitations"
      >
        <section class="sv-r-panel sv-r-panel--tonal sv-r-tone-warm">
          <p class="sv-r-eyebrow sv-r-eyebrow--dot sv-r-tone-warm">
            Owner flow
          </p>
          <h2 class="sv-r-title mt-1">
            Invite an initial branch owner
          </h2>
          <p class="sv-r-panel__desc">
            This owner flow permits Branch Manager and Human Resource invitations only. Operational roles are invited by Human Resource.
          </p>
          <form
            class="mt-4 grid gap-4"
            @submit.prevent="invite"
          >
            <SvTextInput
              id="owner-invite-email"
              v-model="inviteEmail"
              label="Email"
              type="email"
              required
            />
            <SvSelect
              id="owner-invite-role"
              v-model="inviteRole"
              label="Account type"
              :options="roleOptions"
            />
            <SvSelect
              id="owner-invite-branch"
              v-model="inviteBranch"
              label="Branch"
              placeholder="Select a branch"
              :options="branchOptions"
              required
            />
            <div>
              <SvButton
                type="submit"
                :loading="inviting"
              >
                Send invitation
              </SvButton>
            </div>
          </form>
        </section>
        <div class="sv-r-note sv-r-tone-teal">
          <p class="sv-r-note__title">
            Human Resource owns operations
          </p>
          <p class="sv-r-note__body">
            Operational staff setup, eligibility, availability and compensation are managed by Human Resource in each branch, not from this page.
          </p>
        </div>
      </aside>
    </div>
    <SvDialog
      :open="pendingLifecycle !== null"
      title="Confirm access change"
      description="Review the security impact before continuing."
      @close="pendingLifecycle = null"
    >
      <div
        v-if="pendingLifecycle"
        class="space-y-4"
      >
        <p class="text-sm text-text">
          {{ pendingLifecycle.action }} access for <strong>{{ pendingLifecycle.row.display_name }}</strong>. {{ pendingLifecycle.row.active_session_count }} active account-context session(s), unused Magic Links, and pending invitations will be revoked where applicable. Historical records are preserved.
        </p>
        <SvTextInput
          id="lifecycle-reason"
          v-model="lifecycleReason"
          label="Reason (recommended)"
        />
        <div class="flex justify-end gap-2">
          <SvButton
            variant="secondary"
            @click="pendingLifecycle = null"
          >
            Cancel
          </SvButton><SvButton
            :loading="directory.mutating === pendingLifecycle.row.id"
            @click="executeStatus"
          >
            Confirm {{ pendingLifecycle.action }}
          </SvButton>
        </div>
      </div>
    </SvDialog>
  </section>
</template>

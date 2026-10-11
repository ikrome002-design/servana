<script setup lang="ts">
/**
 * Account and Preferences — Audit, Appendix A §12.4.15 (Phase UI-15).
 *
 * A Profile-style identity and scope header above the shared own-account runtime (sessions, MFA,
 * theme). It always shows the active branch and the caller's current masking and export level
 * (§12.4.15), read from the server-granted permission set. Nothing here can change a role, branch,
 * export or masking permission: each fact names who owns it instead of offering an edit. There is
 * no unmask capability anywhere in Servana, so masking is shown as fixed, not as a setting.
 * Notification preferences belong to the gated Notifications runtime (Phase 21N) and are absent.
 */
import { computed, onMounted } from 'vue';
import AccountAndSecurity from '@/pages/platform/AccountAndSecurity.vue';
import { SvIconLocked, SvIconSecurity } from '@/design-system/icons';
import { useAccountContextStore } from '@/stores/accountContextStore';
import { useAuthStore } from '@/stores/authStore';
import { usePermissionStore } from '@/stores/permissionStore';

const auth = useAuthStore();
const contexts = useAccountContextStore();
const permissions = usePermissionStore();

const name = computed(() => auth.user?.name ?? 'Your account');
const initials = computed(() => name.value.split(/\s+/).filter(Boolean).map((part) => part[0]).slice(0, 2).join('').toUpperCase());
const branch = computed(() => contexts.currentContext?.branch_name ?? null);
const merchant = computed(() => contexts.currentContext?.merchant_name ?? null);

const grants = computed(() => [
  { label: 'Branch events', granted: permissions.can('audit.branch_events.view') },
  { label: 'Finance audit', granted: permissions.can('audit.finance.view') },
  { label: 'Compensation audit', granted: permissions.can('audit.compensation.view') },
  { label: 'Flag events', granted: permissions.can('audit.flagged_event.create') },
  { label: 'Work reviews', granted: permissions.can('audit.flagged_event.update_status') },
  { label: 'Resolve or dismiss', granted: permissions.can('audit.flagged_event.resolve_metadata') },
]);

const facts = computed(() => [
  { label: 'Role', value: 'Audit — read-only reviewer', owner: 'Set by your merchant' },
  { label: 'Active branch', value: branch.value ?? 'Assigned branch', owner: 'Assigned by your merchant' },
  { label: 'Export level', value: permissions.can('audit.export') ? 'Masked exports, reason required' : 'No export access', owner: 'Granted by your merchant' },
  { label: 'Masking', value: 'Always masked', owner: 'Enforced by the server' },
]);

onMounted(() => {
  if (!contexts.loaded) void contexts.fetchContexts();
});
</script>

<template>
  <div
    class="mx-auto w-full max-w-4xl"
    data-testid="audit-account-page"
  >
    <header class="sv-profile-hero relative isolate mb-5 overflow-hidden rounded-card border border-sv-border shadow-raised">
      <div
        aria-hidden="true"
        class="sv-profile-hero__band h-20 md:h-24"
      />
      <div class="px-5 pb-6 md:px-8">
        <div class="flex flex-wrap items-end gap-4">
          <span
            aria-hidden="true"
            class="sv-profile-hero__avatar -mt-10 inline-flex h-20 w-20 shrink-0 items-center justify-center rounded-full font-display text-2xl font-extrabold"
          >{{ initials }}</span>
          <div class="min-w-0 pt-3">
            <p class="text-xs font-semibold uppercase tracking-[0.16em] text-sv-text-muted">
              Account and preferences
            </p>
            <h1 class="font-display text-2xl font-extrabold text-sv-text-heading md:text-3xl">
              {{ name }}
            </h1>
            <p class="text-sm text-sv-text-secondary">
              Audit<template v-if="branch">
                · {{ branch }}
              </template><template v-if="merchant">
                · {{ merchant }}
              </template>
            </p>
          </div>
          <span class="ml-auto inline-flex items-center gap-1 rounded-full border border-sv-info-border bg-sv-info-bg px-3 py-1 text-xs font-semibold text-sv-info-fg">
            <SvIconSecurity
              aria-hidden="true"
              class="h-4 w-4"
            />Read-only reviewer
          </span>
        </div>
        <dl class="mt-6 grid grid-cols-2 gap-3 md:grid-cols-4">
          <div
            v-for="fact in facts"
            :key="fact.label"
            class="rounded-control border border-sv-border bg-sv-surface-raised p-3"
            :data-testid="`audit-account-fact-${fact.label.toLowerCase().replace(/\s+/g, '-')}`"
          >
            <dt class="flex items-center gap-1 text-xs text-sv-text-muted">
              <SvIconLocked
                aria-hidden="true"
                class="h-3.5 w-3.5"
              />{{ fact.label }}
            </dt>
            <dd class="mt-1 text-sm font-semibold text-sv-text-heading">
              {{ fact.value }}
            </dd>
            <dd class="text-xs text-sv-text-muted">
              {{ fact.owner }}
            </dd>
          </div>
        </dl>
        <div class="mt-5">
          <h2 class="text-xs font-semibold uppercase tracking-wide text-sv-text-muted">
            Audit permissions on this account
          </h2>
          <ul
            class="mt-2 flex flex-wrap gap-2"
            data-testid="audit-account-grants"
          >
            <li
              v-for="grant in grants"
              :key="grant.label"
              class="inline-flex items-center gap-1 rounded-pill border px-3 py-1 text-xs font-semibold"
              :class="grant.granted ? 'border-sv-success-border bg-sv-success-bg text-sv-success-fg' : 'border-sv-border bg-sv-surface-subtle text-sv-text-secondary'"
            >
              <span class="sr-only">{{ grant.granted ? 'Granted:' : 'Not granted:' }}</span>{{ grant.label }}<span aria-hidden="true">{{ grant.granted ? '' : ' — not granted' }}</span>
            </li>
          </ul>
          <p class="mt-2 text-xs text-sv-text-muted">
            Permissions are set by your merchant and checked by the server on every request. They cannot be changed here.
          </p>
        </div>
      </div>
    </header>
    <AccountAndSecurity experience="audit" />
  </div>
</template>

<style scoped>
.sv-profile-hero {
  background: var(--sv-color-surface-raised);
}
.sv-profile-hero__band {
  background:
    radial-gradient(circle at 85% 0%, color-mix(in srgb, var(--sv-color-accent) 40%, transparent), transparent 55%),
    linear-gradient(120deg, color-mix(in srgb, var(--sv-color-brand-secondary) 60%, var(--sv-color-surface-raised)), color-mix(in srgb, var(--sv-color-growth) 40%, var(--sv-color-surface-raised)));
}
.sv-profile-hero__avatar {
  background: color-mix(in srgb, var(--sv-color-brand-secondary) 18%, var(--sv-color-surface-raised));
  color: var(--sv-color-text-heading);
  box-shadow: 0 0 0 4px var(--sv-color-surface-raised), var(--sv-shadow-card);
}
</style>

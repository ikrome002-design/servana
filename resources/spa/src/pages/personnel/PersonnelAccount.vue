<script setup lang="ts">
import { computed, onMounted } from 'vue';
import AccountAndSecurity from '@/pages/platform/AccountAndSecurity.vue';
import { SvIconLocked, SvIconSecurity } from '@/design-system/icons';
import { useAuthStore } from '@/stores/authStore';
import { usePersonnelExperienceStore } from '@/stores/personnelExperienceStore';

/*
 * Account and preferences — Personnel (UI-14). A Profile-style identity header above the shared
 * own-account runtime (sessions, MFA, theme). The header is read-only: role, branch and pay terms
 * are owned by the merchant and Human Resource, and each says so instead of offering an edit.
 */
const auth = useAuthStore();
const experience = usePersonnelExperienceStore();
const name = computed(() => auth.user?.name ?? experience.workspace?.staff.display_name ?? 'Your account');
const initials = computed(() => name.value.split(/\s+/).filter(Boolean).map((part) => part[0]).slice(0, 2).join('').toUpperCase());
const branch = computed(() => experience.workspace?.staff.branch?.name ?? null);
const roleTitle = computed(() => experience.workspace?.staff.role_title ?? 'Personnel');
const facts = computed(() => [
  { label: 'Role', value: roleTitle.value, owner: 'Set by your merchant' },
  { label: 'Branch', value: branch.value ?? 'Assigned branch', owner: 'Set by Human Resource' },
  { label: 'Sign-in', value: 'Magic Link', owner: 'No passwords in Servana' },
  { label: 'Pay terms', value: 'Read-only', owner: 'Maintained by Human Resource' },
]);
onMounted(() => { void experience.fetchWorkspace(); });
</script>

<template>
  <div class="mx-auto w-full max-w-4xl">
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
              {{ roleTitle }}<template v-if="branch">
                · {{ branch }}
              </template>
            </p>
          </div>
          <span class="ml-auto inline-flex items-center gap-1 rounded-full border border-sv-success-border bg-sv-success-bg px-3 py-1 text-xs font-semibold text-sv-success-fg">
            <SvIconSecurity
              aria-hidden="true"
              class="h-4 w-4"
            />Own account only
          </span>
        </div>
        <dl class="mt-6 grid grid-cols-2 gap-3 md:grid-cols-4">
          <div
            v-for="fact in facts"
            :key="fact.label"
            class="rounded-control border border-sv-border bg-sv-surface-raised p-3"
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
      </div>
    </header>
    <AccountAndSecurity experience="personnel" />
  </div>
</template>

<style scoped>
.sv-profile-hero {
  background: var(--sv-color-surface-raised);
}
.sv-profile-hero__band {
  background:
    radial-gradient(circle at 85% 0%, color-mix(in srgb, var(--sv-color-accent) 45%, transparent), transparent 55%),
    linear-gradient(120deg, color-mix(in srgb, var(--sv-color-brand-primary) 55%, var(--sv-color-surface-raised)), color-mix(in srgb, var(--sv-color-brand-secondary) 45%, var(--sv-color-surface-raised)));
}
.sv-profile-hero__avatar {
  background: color-mix(in srgb, var(--sv-color-brand-secondary) 18%, var(--sv-color-surface-raised));
  color: var(--sv-color-text-heading);
  box-shadow: 0 0 0 4px var(--sv-color-surface-raised), var(--sv-shadow-card);
}
</style>

<script setup lang="ts">
import { computed } from 'vue';
import { useRouter } from 'vue-router';
import PermissionGate from '@/components/auth/PermissionGate.vue';
import FaqAccordion from '@/components/support/FaqAccordion.vue';
import { getFaq, getLandingHero, heroImage, LEGAL_DOCS } from '@/content/roleContent';
import { navigationFor } from '@/navigation/roleNavigation';
import { useAuthStore } from '@/stores/authStore';
import { useGetStartedStore } from '@/stores/getStartedStore';
import { ROLE_ENTRY, type RoleIdentity } from '@/types/roles';

/**
 * Shared role landing surface (Plan §27.2; Scope §3.1). Each role's landing is
 * distinct by construction: its own verbatim hero copy, its own approved imagery,
 * its own live actions, its own get-started progress, its own FAQ, and its own
 * legal footer. It is a live role entry surface — not a marketing shell — and it
 * truthfully separates what can be done now from what requires setup or is
 * planned. No planned capability is ever a live link.
 */
const props = defineProps<{ identity: RoleIdentity }>();

const auth = useAuthStore();
const router = useRouter();
const getStarted = useGetStartedStore();

const entry = computed(() => ROLE_ENTRY[props.identity]);
const hero = computed(() => getLandingHero(props.identity));
const faq = computed(() => getFaq(props.identity));
const nav = computed(() => navigationFor(props.identity));

const liveActions = computed(() =>
  nav.value.filter(
    (item) =>
      item.availability === 'live' &&
      item.routeName !== entry.value.landingRouteName &&
      item.routeName !== entry.value.getStartedRouteName,
  ),
);
const plannedItems = computed(() => nav.value.filter((item) => item.availability === 'planned'));

const progress = computed(() => {
  const userId = auth.user?.id;
  if (!userId) return { completed: 0, total: 0, percent: 0 };
  return getStarted.progress(userId, props.identity);
});

// Branch-scoped roles surface a clear no-branch state (Scope §4.3.1, §4.4–§4.8).
const BRANCH_SCOPED: RoleIdentity[] = [
  'merchant_branch',
  'merchant_human_resource',
  'merchant_finance',
  'merchant_front_office',
  'merchant_personnel',
  'merchant_audit',
];
const needsBranch = computed(
  () => BRANCH_SCOPED.includes(props.identity) && auth.branchIds.length === 0,
);

function goGetStarted(): void {
  void router.push({ name: entry.value.getStartedRouteName });
}
</script>

<template>
  <div class="mx-auto max-w-6xl space-y-10">
    <!-- Hero: asymmetric copy + approved imagery (aspect-ratio reserved to avoid CLS). -->
    <section class="grid items-center gap-6 lg:grid-cols-[1.3fr_1fr]">
      <div>
        <p class="text-sm font-semibold uppercase tracking-wide text-accent">
          {{ entry.label }}
        </p>
        <h1 class="mt-2 font-display text-3xl font-extrabold text-heading md:text-4xl">
          {{ hero.title || `Welcome, ${auth.user?.name ?? 'there'}` }}
        </h1>
        <p
          v-for="(line, idx) in hero.body"
          :key="idx"
          class="mt-3 text-text-muted"
        >
          {{ line }}
        </p>
        <div class="mt-6 flex flex-wrap gap-3">
          <button
            type="button"
            class="inline-flex min-h-[44px] items-center rounded-control bg-primary px-5 py-2 text-sm font-semibold text-brand-deep hover:bg-orange-400 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-2"
            @click="goGetStarted"
          >
            Continue get-started
          </button>
        </div>
      </div>
      <div class="overflow-hidden rounded-card bg-surface-alt shadow-card">
        <img
          :src="heroImage(identity)"
          :alt="`${entry.label} working in Servana`"
          class="aspect-[4/3] w-full object-cover"
          width="800"
          height="600"
        >
      </div>
    </section>

    <!-- No-branch state for branch-scoped roles. -->
    <section
      v-if="needsBranch"
      role="status"
      class="rounded-card border border-border bg-surface-alt p-5"
    >
      <h2 class="font-display text-base font-semibold text-heading">
        Waiting on a branch assignment
      </h2>
      <p class="mt-1 text-sm text-text-muted">
        Your account isn't assigned to a branch yet. Ask your HR or Merchant Administrator to
        assign you, then your branch work will appear here.
      </p>
    </section>

    <!-- Get-started progress -->
    <section
      class="rounded-card border border-border bg-surface p-5"
      aria-labelledby="landing-progress-heading"
    >
      <div class="flex items-center justify-between gap-4">
        <div>
          <h2
            id="landing-progress-heading"
            class="font-display text-lg font-bold text-heading"
          >
            Your setup progress
          </h2>
          <p class="mt-1 text-sm text-text-muted">
            {{ progress.completed }} of {{ progress.total }} steps complete
          </p>
        </div>
        <RouterLink
          :to="{ name: entry.getStartedRouteName }"
          class="inline-flex min-h-[44px] items-center rounded-control border border-border px-4 py-2 text-sm font-semibold text-heading hover:bg-surface-alt focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary"
        >
          Open get-started
        </RouterLink>
      </div>
      <div
        role="progressbar"
        :aria-valuenow="progress.percent"
        aria-valuemin="0"
        aria-valuemax="100"
        aria-label="Setup progress"
        class="mt-4 h-2 w-full overflow-hidden rounded-full bg-surface-alt"
      >
        <div
          class="h-full rounded-full bg-primary transition-all motion-reduce:transition-none"
          :style="{ width: `${progress.percent}%` }"
        />
      </div>
    </section>

    <!-- What you can do now -->
    <section aria-labelledby="landing-now-heading">
      <h2
        id="landing-now-heading"
        class="font-display text-lg font-bold text-heading"
      >
        What you can do now
      </h2>
      <p
        v-if="liveActions.length === 0"
        class="mt-2 text-sm text-text-muted"
      >
        Your live tools will appear here as you complete get-started.
      </p>
      <div class="mt-4 grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3">
        <template
          v-for="item in liveActions"
          :key="item.key"
        >
          <PermissionGate
            v-if="item.permission"
            :permission="item.permission"
          >
            <RouterLink
              :to="{ name: item.routeName }"
              class="flex min-h-[44px] flex-col rounded-card border border-border bg-surface p-4 hover:border-primary focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary"
            >
              <span class="font-display text-base font-semibold text-heading">{{ item.label }}</span>
              <span class="mt-1 text-sm text-text-muted">Open {{ item.label.toLowerCase() }}</span>
            </RouterLink>
          </PermissionGate>
          <RouterLink
            v-else
            :to="{ name: item.routeName }"
            class="flex min-h-[44px] flex-col rounded-card border border-border bg-surface p-4 hover:border-primary focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary"
          >
            <span class="font-display text-base font-semibold text-heading">{{ item.label }}</span>
            <span class="mt-1 text-sm text-text-muted">Open {{ item.label.toLowerCase() }}</span>
          </RouterLink>
        </template>
      </div>
    </section>

    <!-- Coming with later phases (truthful; never linked) -->
    <section
      v-if="plannedItems.length > 0"
      aria-labelledby="landing-planned-heading"
    >
      <h2
        id="landing-planned-heading"
        class="font-display text-lg font-bold text-heading"
      >
        Coming soon
      </h2>
      <ul class="mt-4 grid grid-cols-1 gap-2 sm:grid-cols-2 lg:grid-cols-3">
        <li
          v-for="item in plannedItems"
          :key="item.key"
          class="flex items-center justify-between gap-2 rounded-card border border-dashed border-border bg-surface-alt px-4 py-3 text-sm text-text-muted"
        >
          <span>{{ item.label }}</span>
          <span class="rounded-full bg-surface px-2 py-0.5 text-[11px] font-semibold uppercase tracking-wide">
            {{ item.phase }}
          </span>
        </li>
      </ul>
    </section>

    <!-- FAQ -->
    <section
      v-if="faq.length > 0"
      aria-labelledby="landing-faq-heading"
    >
      <h2
        id="landing-faq-heading"
        class="font-display text-lg font-bold text-heading"
      >
        Frequently asked questions
      </h2>
      <div class="mt-4">
        <FaqAccordion :items="faq" />
      </div>
    </section>

    <!-- Legal footer -->
    <footer class="border-t border-border pt-6">
      <h2 class="sr-only">
        Legal documents
      </h2>
      <p class="text-sm text-text-muted">
        Documents governing your {{ entry.label }} account:
      </p>
      <ul class="mt-2 flex flex-wrap gap-x-6 gap-y-2">
        <li
          v-for="doc in LEGAL_DOCS"
          :key="doc.type"
        >
          <RouterLink
            :to="{ name: 'legal.document', params: { role: identity, doc: doc.type } }"
            class="text-sm font-medium text-heading underline hover:no-underline focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary"
          >
            {{ doc.title }}
          </RouterLink>
        </li>
      </ul>
    </footer>
  </div>
</template>

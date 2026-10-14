<script setup lang="ts">
import { computed, ref } from 'vue';
import SvDialog from '@/components/ui/SvDialog.vue';
import LegalAcknowledgement from '@/components/legal/LegalAcknowledgement.vue';
import { getStartedChecklist } from '@/content/getStartedContent';
import { useRefinedExperience } from '@/design-system/experience';
import { SvIconCheck, SvIconChevronRight, SvIconLocked } from '@/design-system/icons';
import { useGetStartedStore } from '@/stores/getStartedStore';
import type { RoleIdentity } from '@/types/roles';

/**
 * Guided get-started checklist (Plan §27.2; Scope §3.2). Persists completion,
 * dismissal, and the legal acknowledgement per user + role (getStartedStore).
 * Fully keyboard operable; progress is announced via an aria-live region. Deep
 * links target only live routes; future steps show their owning phase and never
 * link anywhere.
 */
const props = withDefaults(defineProps<{
  identity: RoleIdentity;
  userId: string;
  observedCompletedIds?: string[];
}>(), { observedCompletedIds: () => [] });
const emit = defineEmits<{ dismiss: [] }>();

const store = useGetStartedStore();
/** COR-UI08-UI12-VIS-001: the five corrective accounts render the milestone-journey template. */
const refined = useRefinedExperience();
const items = computed(() => getStartedChecklist(props.identity));
const observed = computed(() => new Set(props.observedCompletedIds));
const isCompleted = (itemId: string): boolean => observed.value.has(itemId)
  || store.isCompleted(props.userId, props.identity, itemId);
const progress = computed(() => {
  const completed = items.value.filter((item) => isCompleted(item.id)).length;
  const total = items.value.length;
  return { completed, total, percent: total === 0 ? 0 : Math.round((completed / total) * 100) };
});

/** The first incomplete step that has a live destination — the one the page leads with. */
const nextItemId = computed(() =>
  items.value.find((item) => !isCompleted(item.id) && (item.kind === 'acknowledge' || (item.kind === 'action' && item.routeName)))?.id ?? null,
);

/** The state a step is in — one vocabulary for both templates. */
function stepState(itemId: string, kind: string, routeName: unknown): 'done' | 'next' | 'later' | 'open' {
  if (isCompleted(itemId)) return 'done';
  if (itemId === nextItemId.value) return 'next';
  if (kind === 'action' && !routeName) return 'later';
  return 'open';
}

/** The step the progress panel names as next. */
const nextItem = computed(() => items.value.find((item) => item.id === nextItemId.value) ?? null);

/** SVG ring geometry for the progress dial (decorative; the numbers are rendered as text). */
const RING_RADIUS = 34;
const RING_CIRCUMFERENCE = 2 * Math.PI * RING_RADIUS;
const ringOffset = computed(() => RING_CIRCUMFERENCE * (1 - progress.value.percent / 100));

const ackOpen = ref(false);

function toggle(itemId: string): void {
  const item = items.value.find((candidate) => candidate.id === itemId);
  if (item?.completion === 'server') return;
  store.toggle(props.userId, props.identity, itemId);
}

function onAcknowledged(): void {
  store.acknowledgeLegal(props.userId, props.identity);
  ackOpen.value = false;
}
</script>

<template>
  <!--
    Phase UI-16 (UI16-VIS-002): the checklist used to be a stack of identical bordered checkbox rows
    under a thin progress bar, the same on every account. It is now a two-part composition — a
    progress panel that names the next step, beside numbered step cards that separate "done",
    "next" and "owned by a later phase" — while every behaviour is unchanged: the same checkboxes
    and test hooks, server-observed items still cannot be claimed, the acknowledgement still opens
    the legal dialog, and future steps still link nowhere.
  -->
  <section
    v-if="!refined"
    aria-labelledby="get-started-heading"
    class="grid gap-5 lg:grid-cols-[minmax(0,18rem)_minmax(0,1fr)] lg:items-start"
  >
    <aside class="sv-checklist-progress rounded-card border border-sv-border p-5 shadow-card lg:sticky lg:top-4">
      <h2
        id="get-started-heading"
        class="font-display text-xl font-extrabold text-sv-text-heading"
      >
        Get started
      </h2>
      <p class="mt-1 text-sm leading-6 text-sv-text-muted">
        A guided checklist to set up and take your first actions. Your progress is saved on
        this device.
      </p>

      <div class="mt-5 flex items-center gap-4">
        <svg
          viewBox="0 0 80 80"
          class="h-20 w-20 shrink-0 -rotate-90"
          aria-hidden="true"
        >
          <circle
            cx="40"
            cy="40"
            :r="RING_RADIUS"
            class="sv-checklist-ring__track"
            fill="none"
            stroke-width="8"
          />
          <circle
            cx="40"
            cy="40"
            :r="RING_RADIUS"
            class="sv-checklist-ring__value motion-reduce:transition-none"
            fill="none"
            stroke-width="8"
            stroke-linecap="round"
            :stroke-dasharray="RING_CIRCUMFERENCE"
            :stroke-dashoffset="ringOffset"
          />
        </svg>
        <div>
          <p class="text-xs font-semibold uppercase tracking-wide text-sv-text-muted">
            Setup progress
          </p>
          <p class="mt-1 font-display text-3xl font-extrabold leading-none text-sv-text-heading">
            {{ progress.percent }}%
          </p>
          <p
            class="mt-1 text-sm font-semibold text-sv-text"
            aria-live="polite"
          >
            {{ progress.completed }} of {{ progress.total }} complete
          </p>
        </div>
      </div>

      <div
        role="progressbar"
        :aria-valuenow="progress.percent"
        aria-valuemin="0"
        aria-valuemax="100"
        aria-label="Get-started progress"
        class="mt-4 h-1.5 w-full overflow-hidden rounded-pill bg-sv-surface-subtle"
      >
        <div
          class="h-full rounded-pill bg-sv-brand transition-all duration-sv-normal motion-reduce:transition-none"
          :style="{ width: `${progress.percent}%` }"
        />
      </div>

      <button
        type="button"
        class="sv-focus-ring mt-5 inline-flex min-h-sv-touch items-center rounded-control px-3 py-2 text-sm font-medium text-sv-text-muted hover:bg-sv-surface-subtle"
        data-testid="dismiss-get-started"
        @click="emit('dismiss')"
      >
        Dismiss for now
      </button>
    </aside>

    <ol class="flex flex-col gap-3">
      <li
        v-for="(item, index) in items"
        :key="item.id"
        class="sv-checklist-step flex flex-col gap-3 rounded-card border p-4 md:flex-row md:items-center md:justify-between"
        :data-state="isCompleted(item.id) ? 'done' : item.id === nextItemId ? 'next' : (item.kind === 'action' && !item.routeName) ? 'later' : 'open'"
      >
        <label class="flex min-w-0 flex-1 items-start gap-3 text-sm text-sv-text">
          <input
            type="checkbox"
            class="sv-checklist-step__input sr-only"
            :checked="isCompleted(item.id)"
            :disabled="item.kind === 'acknowledge' || item.completion === 'server'"
            :data-testid="`checklist-${item.id}`"
            @change="toggle(item.id)"
          >
          <span
            aria-hidden="true"
            class="sv-checklist-step__marker mt-0.5 inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-full font-display text-sm font-extrabold"
          >
            <SvIconCheck
              v-if="isCompleted(item.id)"
              class="h-5 w-5"
            />
            <SvIconLocked
              v-else-if="item.kind === 'action' && !item.routeName"
              class="h-4 w-4"
            />
            <template v-else>{{ index + 1 }}</template>
          </span>
          <span class="min-w-0">
            <span
              class="block leading-6"
              :class="isCompleted(item.id) ? 'font-medium text-sv-text-heading' : 'font-semibold text-sv-text-heading'"
            >{{ item.label }}</span>
            <span class="mt-1 flex flex-wrap items-center gap-2 text-xs text-sv-text-muted">
              <span
                v-if="item.id === nextItemId"
                class="sv-checklist-step__next rounded-pill px-2 py-0.5 font-semibold uppercase tracking-wide"
              >Up next</span>
              <span v-if="item.responsibleRole">{{ isCompleted(item.id) ? 'Observed complete' : 'Next action' }} · {{ item.responsibleRole }}</span>
            </span>
          </span>
        </label>

        <RouterLink
          v-if="item.kind === 'action' && item.routeName"
          :to="{ name: item.routeName }"
          class="sv-checklist-step__action sv-focus-ring inline-flex min-h-sv-touch shrink-0 items-center justify-center gap-1 self-start rounded-control px-4 py-2 text-sm font-semibold md:self-auto"
        >
          Open<SvIconChevronRight
            aria-hidden="true"
            class="h-4 w-4"
          />
        </RouterLink>
        <button
          v-else-if="item.kind === 'acknowledge'"
          type="button"
          class="sv-focus-ring inline-flex min-h-sv-touch shrink-0 items-center self-start rounded-control border border-sv-border-strong bg-sv-surface-raised px-4 py-2 text-sm font-semibold text-sv-text-heading hover:bg-sv-surface-subtle disabled:opacity-60 md:self-auto"
          :disabled="store.isLegalAcknowledged(userId, identity)"
          data-testid="open-acknowledgement"
          @click="ackOpen = true"
        >
          {{ store.isLegalAcknowledged(userId, identity) ? 'Acknowledged' : 'Review & acknowledge' }}
        </button>
        <span
          v-else
          class="inline-flex shrink-0 items-center gap-1 self-start rounded-pill border border-sv-border bg-sv-surface-subtle px-2.5 py-1 text-[11px] font-semibold uppercase tracking-wide text-sv-text-muted md:self-auto"
          :title="`Available in ${item.phase}`"
        >{{ item.phase }}</span>
      </li>
    </ol>

    <SvDialog
      :open="ackOpen"
      title="Acknowledge your role's terms"
      description="Please review and accept the documents that govern your account."
      @close="ackOpen = false"
    >
      <LegalAcknowledgement
        :identity="identity"
        @acknowledged="onAcknowledged"
      />
    </SvDialog>
  </section>
  <!--
    COR-UI08-UI12-VIS-001 — the milestone journey (Merchant Administrator, Branch, Human Resource,
    Finance). The progress narrative leads on the dark feature surface: the ring, the server-observed
    count, the step that is up next with its one action, and "Dismiss for now". The steps then run
    down a milestone rail whose markers say done / next / later. Same checkboxes, same test hooks,
    same rules: server-observed items cannot be claimed, the acknowledgement opens the legal
    dialog, and a later-phase step links nowhere.
  -->
  <section
    v-else
    aria-labelledby="get-started-heading"
    class="sv-r-stack"
  >
    <div class="sv-r-feature">
      <div class="grid gap-6 lg:grid-cols-[minmax(0,1fr)_minmax(0,1.15fr)] lg:items-center">
        <div>
          <h2
            id="get-started-heading"
            class="sv-r-feature__title"
          >
            Get started
          </h2>
          <p class="sv-r-feature__hint mt-1 max-w-md">
            A guided checklist to set up and take your first actions. Your progress is saved on
            this device.
          </p>
          <div class="mt-5 flex items-center gap-5">
            <svg
              viewBox="0 0 80 80"
              class="h-24 w-24 shrink-0 -rotate-90"
              aria-hidden="true"
            >
              <circle
                cx="40"
                cy="40"
                :r="RING_RADIUS"
                class="sv-checklist-ring__track--feature"
                fill="none"
                stroke-width="8"
              />
              <circle
                cx="40"
                cy="40"
                :r="RING_RADIUS"
                class="sv-checklist-ring__value--feature motion-reduce:transition-none"
                fill="none"
                stroke-width="8"
                stroke-linecap="round"
                :stroke-dasharray="RING_CIRCUMFERENCE"
                :stroke-dashoffset="ringOffset"
              />
            </svg>
            <div>
              <p class="sv-r-feature__eyebrow">
                Setup progress
              </p>
              <p class="sv-r-feature__value sv-r-figure-xl mt-1">
                {{ progress.percent }}%
              </p>
              <p
                class="sv-r-feature__label mt-1"
                aria-live="polite"
              >
                {{ progress.completed }} of {{ progress.total }} complete
              </p>
            </div>
          </div>
          <div
            role="progressbar"
            :aria-valuenow="progress.percent"
            aria-valuemin="0"
            aria-valuemax="100"
            aria-label="Get-started progress"
            class="mt-5 h-1.5 w-full max-w-sm overflow-hidden rounded-pill bg-white/15"
          >
            <div
              class="h-full rounded-pill bg-sv-accent transition-all duration-sv-normal motion-reduce:transition-none"
              :style="{ width: `${progress.percent}%` }"
            />
          </div>
        </div>

        <div class="sv-r-feature__cell">
          <p class="sv-r-feature__eyebrow">
            Up next
          </p>
          <p
            v-if="nextItem"
            class="sv-r-feature__title"
          >
            {{ nextItem.label }}
          </p>
          <p
            v-else
            class="sv-r-feature__title"
          >
            Every available step is complete
          </p>
          <p
            v-if="nextItem?.responsibleRole"
            class="sv-r-feature__hint mt-1"
          >
            Next action · {{ nextItem.responsibleRole }}
          </p>
          <button
            type="button"
            class="sv-r-feature-action sv-r-feature-action--ghost sv-focus-ring mt-4"
            data-testid="dismiss-get-started"
            @click="emit('dismiss')"
          >
            Dismiss for now
          </button>
        </div>
      </div>
    </div>

    <ol class="sv-r-steps">
      <li
        v-for="(item, index) in items"
        :key="item.id"
        class="sv-r-step sv-checklist-milestone"
        :class="{
          'sv-r-step--done': stepState(item.id, item.kind, item.routeName) === 'done',
          'sv-r-step--current': stepState(item.id, item.kind, item.routeName) === 'next',
          'sv-r-step--future': stepState(item.id, item.kind, item.routeName) === 'later',
        }"
        :data-state="stepState(item.id, item.kind, item.routeName)"
      >
        <span
          aria-hidden="true"
          class="sv-r-step__marker"
        >
          <SvIconCheck
            v-if="isCompleted(item.id)"
            class="h-5 w-5"
          />
          <SvIconLocked
            v-else-if="item.kind === 'action' && !item.routeName"
            class="h-4 w-4"
          />
          <template v-else>{{ index + 1 }}</template>
        </span>
        <div class="sv-r-record flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
          <label class="flex min-w-0 flex-1 items-start gap-3 text-sm text-sv-text">
            <input
              type="checkbox"
              class="sr-only"
              :checked="isCompleted(item.id)"
              :disabled="item.kind === 'acknowledge' || item.completion === 'server'"
              :data-testid="`checklist-${item.id}`"
              @change="toggle(item.id)"
            >
            <span class="min-w-0">
              <span class="block font-semibold leading-6 text-sv-text-heading">{{ item.label }}</span>
              <span class="mt-1 flex flex-wrap items-center gap-2 text-xs text-sv-text-muted">
                <span
                  v-if="item.id === nextItemId"
                  class="sv-r-chip sv-r-chip--tone sv-r-tone-warm"
                >Up next</span>

                <span v-if="item.responsibleRole">{{ isCompleted(item.id) ? 'Observed complete' : 'Next action' }} · {{ item.responsibleRole }}</span>
              </span>
            </span>
          </label>

          <RouterLink
            v-if="item.kind === 'action' && item.routeName"
            :to="{ name: item.routeName }"
            class="sv-focus-ring shrink-0 self-start md:self-auto"
            :class="item.id === nextItemId ? 'sv-r-action' : 'sv-r-action sv-r-action--quiet'"
          >
            Open<SvIconChevronRight
              aria-hidden="true"
              class="h-4 w-4"
            />
          </RouterLink>
          <button
            v-else-if="item.kind === 'acknowledge'"
            type="button"
            class="sv-r-action sv-r-action--quiet sv-focus-ring shrink-0 self-start disabled:opacity-60 md:self-auto"
            :disabled="store.isLegalAcknowledged(userId, identity)"
            data-testid="open-acknowledgement"
            @click="ackOpen = true"
          >
            {{ store.isLegalAcknowledged(userId, identity) ? 'Acknowledged' : 'Review & acknowledge' }}
          </button>
          <span
            v-else
            class="sv-r-chip shrink-0 self-start uppercase tracking-wide md:self-auto"
            :title="`Available in ${item.phase}`"
          >{{ item.phase }}</span>
        </div>
      </li>
    </ol>

    <SvDialog
      :open="ackOpen"
      title="Acknowledge your role's terms"
      description="Please review and accept the documents that govern your account."
      @close="ackOpen = false"
    >
      <LegalAcknowledgement
        :identity="identity"
        @acknowledged="onAcknowledged"
      />
    </SvDialog>
  </section>
</template>

<style scoped>
.sv-checklist-progress {
  background:
    radial-gradient(circle at 100% 0%, color-mix(in srgb, var(--sv-color-accent) 22%, transparent), transparent 55%),
    var(--sv-color-surface-raised);
}
.sv-checklist-ring__track { stroke: var(--sv-color-surface-subtle); }
.sv-checklist-ring__track--feature { stroke: color-mix(in srgb, var(--sv-color-refined-feature-fg) 16%, transparent); }
.sv-checklist-ring__value--feature {
  stroke: var(--sv-color-refined-feature-accent);
  transition: stroke-dashoffset var(--sv-motion-duration-normal) var(--sv-motion-ease-standard);
}
.sv-checklist-milestone:has(input:focus-visible) .sv-r-step__marker {
  outline: var(--sv-focus-ring-width) solid var(--sv-color-focus-ring);
  outline-offset: var(--sv-focus-ring-offset);
}
.sv-checklist-milestone[data-state='next'] .sv-r-record {
  border-color: color-mix(in srgb, var(--sv-color-brand-primary) 55%, var(--sv-color-refined-hairline));
  box-shadow: var(--sv-shadow-float);
}
.sv-checklist-ring__value {
  stroke: var(--sv-color-brand-primary);
  transition: stroke-dashoffset var(--sv-motion-duration-normal) var(--sv-motion-ease-standard);
}
.sv-checklist-step {
  border-color: var(--sv-color-border-default);
  background: var(--sv-color-surface-raised);
}
.sv-checklist-step__marker {
  background: var(--sv-color-surface-subtle);
  color: var(--sv-color-text-heading);
}
.sv-checklist-step[data-state='done'] {
  border-color: var(--sv-color-status-success-border);
  background: var(--sv-color-status-success-bg);
}
.sv-checklist-step[data-state='done'] .sv-checklist-step__marker {
  background: var(--sv-color-growth);
  color: var(--sv-color-text-inverse);
}
.sv-checklist-step[data-state='next'] {
  border-color: color-mix(in srgb, var(--sv-color-brand-primary) 55%, var(--sv-color-border-default));
  box-shadow: var(--sv-shadow-card);
}
.sv-checklist-step[data-state='next'] .sv-checklist-step__marker {
  background: var(--sv-color-brand-primary);
  color: var(--sv-color-text-on-brand);
}
.sv-checklist-step[data-state='later'] .sv-checklist-step__marker {
  color: var(--sv-color-text-muted);
}
.sv-checklist-step__next {
  background: color-mix(in srgb, var(--sv-color-brand-primary) 16%, var(--sv-color-surface-raised));
  color: var(--sv-color-text-heading);
}
.sv-checklist-step__input:focus-visible + .sv-checklist-step__marker {
  outline: var(--sv-focus-ring-width) solid var(--sv-color-focus-ring);
  outline-offset: var(--sv-focus-ring-offset);
}
.sv-checklist-step__action {
  background: var(--sv-color-surface-subtle);
  color: var(--sv-color-text-heading);
}
.sv-checklist-step__action:hover { background: var(--sv-color-surface-warm); }
.sv-checklist-step[data-state='next'] .sv-checklist-step__action {
  background: var(--sv-color-brand-primary);
  color: var(--sv-color-text-on-brand);
}
</style>

<script setup lang="ts">
/**
 * Grouped header primary navigation (Phase UI-08; UI/UX plan §5.4, ADR-018/ADR-019).
 *
 * The Super Administrator is the ONE account whose primary navigation lives in the header
 * (ADR-018). UI-08 gives it 22 contract entries across 8 groups, which a flat inline list cannot
 * carry — so groups become disclosures and the tail of the list moves into an overflow.
 *
 * ## Why a disclosure, and not `role="menu"`
 *
 * `SvMenu` is deliberately scoped to ACTION menus, and says why: the ARIA menu pattern hijacks the
 * arrow keys and suppresses a screen reader's link semantics, which makes navigation worse rather
 * than better. Applying it here would regress the very thing this component exists to improve.
 * Each group is therefore a disclosure button (`aria-expanded` + `aria-controls`) revealing a list
 * of real links, which keeps link semantics, "open in new tab", and the browser's own affordances
 * intact. Arrow keys are supported as a convenience within an open group; they are not the
 * contract, because the links are natively tabbable.
 *
 * ## Overflow is CSS, not measurement
 *
 * CLAUDE.md guardrail 1 forbids JavaScript device detection and requires CSS media queries. So the
 * overflow split is DECLARATIVE: the first five groups are always inline, and the remaining groups
 * are inline only from `lg` (the plan's desktop floor) upward, appearing inside a "More" disclosure
 * below that. The config defines only `md` and `lg`, so no other breakpoint is available.
 * Nothing measures the container, so there is no layout-thrash reflow and no width probe.
 *
 * ## One filtered result
 *
 * The nodes come from `navigationTree()` — the same filtered value the tablet and mobile surfaces
 * render. Desktop, tablet and mobile therefore cannot disagree about what a user may see. Hiding a
 * link is discoverability only; the backend remains the security boundary (ADR-017).
 */
import { computed, nextTick, onBeforeUnmount, ref, watch } from 'vue';
import { useRoute } from 'vue-router';
import { SvIconChevronDown } from '@/design-system/icons';
import type { NavigationNode } from '@/navigation/navigationFilter';

const props = withDefaults(
  defineProps<{
    nodes: readonly NavigationNode[];
    /**
     * `header` renders group disclosures with an overflow (desktop and tablet).
     * `stacked` renders every group as an always-open labelled section (the mobile drawer).
     */
    variant?: 'header' | 'stacked';
  }>(),
  { variant: 'header' },
);

const emit = defineEmits<{ navigate: [] }>();

const route = useRoute();

/**
 * The contract's group order (UI/UX plan §5.4). Declared explicitly rather than derived from first
 * appearance, so a re-ordered contract entry can never silently re-order the header.
 */
const GROUP_ORDER: readonly string[] = [
  'Home',
  'Billing & Commercial',
  'Merchants',
  'Billing Operations',
  'Integrations',
  'Reporting & Audit',
  'Platform Administration',
  'Utility',
];

/** Groups always rendered inline, at every width from tablet up. */
const INLINE_GROUP_COUNT = 5;

interface NavGroup {
  readonly name: string;
  readonly id: string;
  readonly items: readonly NavigationNode[];
}

const slug = (value: string): string => value.toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '');

const groups = computed<NavGroup[]>(() => {
  const byName = new Map<string, NavigationNode[]>();

  for (const node of props.nodes) {
    const existing = byName.get(node.group);
    if (existing) existing.push(node);
    else byName.set(node.group, [node]);
  }

  const ordered: NavGroup[] = [];

  for (const name of GROUP_ORDER) {
    const items = byName.get(name);
    if (items && items.length > 0) {
      ordered.push({ name, id: `nav-group-${slug(name)}`, items });
      byName.delete(name);
    }
  }

  // A group the contract introduces later still renders, after the known ones, rather than
  // disappearing because this constant was not updated.
  for (const [name, items] of byName) {
    ordered.push({ name, id: `nav-group-${slug(name)}`, items });
  }

  return ordered;
});

/**
 * The tail groups. They are rendered TWICE in the DOM — inline (revealed from `xl` upward) and
 * inside the overflow (revealed below `xl`) — with CSS deciding which is visible. Exactly one is
 * ever visible, so there is no duplicate primary navigation on screen, and no JavaScript measures
 * the container to make the choice.
 */
const overflowGroups = computed(() => groups.value.slice(INLINE_GROUP_COUNT));

/** The group containing the active route — kept visibly current even while collapsed. */
const activeGroupName = computed<string | null>(() => {
  for (const group of groups.value) {
    if (group.items.some((item) => item.routeName !== null && item.routeName === route.name)) {
      return group.name;
    }
  }
  return null;
});

const isActive = (item: NavigationNode): boolean =>
  item.routeName !== null && item.routeName === route.name;

// ---------------------------------------------------------------------------------------------
// Disclosure state
// ---------------------------------------------------------------------------------------------

/** At most one disclosure is open: two open panels would overlap and trap the pointer. */
const openId = ref<string | null>(null);
const triggerRefs = ref<Record<string, HTMLButtonElement | null>>({});
const rootRef = ref<HTMLElement | null>(null);

function setTriggerRef(id: string, el: unknown): void {
  triggerRefs.value[id] = (el as HTMLButtonElement | null) ?? null;
}

/**
 * Look up an open panel by its id.
 *
 * Deliberately NOT `CSS.escape`: it is absent in jsdom and is not universally available, so using
 * it here threw `Cannot read properties of undefined (reading 'escape')` in the component tests —
 * and would have thrown in any environment lacking it. No escaping is needed, because every id is
 * produced by `slug()` (or is the literal overflow id), which restricts it to `[a-z0-9-]`. The
 * safety comes from how the id is BUILT rather than from escaping it at the point of use.
 */
function panelById(id: string): HTMLElement | null {
  return rootRef.value?.querySelector<HTMLElement>(`#${id}`) ?? null;
}

async function open(id: string): Promise<void> {
  openId.value = id;
  await nextTick();
  const panel = panelById(id);
  panel?.querySelector<HTMLElement>('a, [aria-disabled="true"]')?.focus();
}

function close(id: string | null = openId.value, returnFocus = true): void {
  if (id !== null && returnFocus) {
    triggerRefs.value[id]?.focus();
  }
  openId.value = null;
}

function toggle(id: string): void {
  if (openId.value === id) close(id, false);
  else void open(id);
}

function onPanelKeydown(event: KeyboardEvent, id: string): void {
  if (event.key === 'Escape') {
    event.preventDefault();
    close(id);
    return;
  }

  if (event.key !== 'ArrowDown' && event.key !== 'ArrowUp' && event.key !== 'Home' && event.key !== 'End') {
    return;
  }

  const panel = panelById(id);
  const focusable = Array.from(panel?.querySelectorAll<HTMLElement>('a, [aria-disabled="true"]') ?? []);
  if (focusable.length === 0) return;

  event.preventDefault();
  const current = focusable.indexOf(document.activeElement as HTMLElement);

  if (event.key === 'Home') focusable[0].focus();
  else if (event.key === 'End') focusable[focusable.length - 1].focus();
  else if (event.key === 'ArrowDown') focusable[(current + 1) % focusable.length].focus();
  else focusable[(current - 1 + focusable.length) % focusable.length].focus();
}

function onTriggerKeydown(event: KeyboardEvent, id: string): void {
  if (event.key === 'ArrowDown') {
    event.preventDefault();
    void open(id);
    return;
  }
  if (event.key === 'Escape' && openId.value === id) {
    event.preventDefault();
    close(id);
  }
}

function onNavigate(): void {
  openId.value = null;
  emit('navigate');
}

function onDocumentPointerDown(event: PointerEvent): void {
  if (openId.value === null) return;
  if (rootRef.value?.contains(event.target as Node | null) === true) return;
  // Focus is NOT returned here: the pointer already moved the user's attention elsewhere, and
  // yanking focus back to the trigger would fight them.
  close(openId.value, false);
}

watch(openId, (value) => {
  if (value !== null) {
    document.addEventListener('pointerdown', onDocumentPointerDown, true);
    return;
  }
  document.removeEventListener('pointerdown', onDocumentPointerDown, true);
});

// Any route change collapses the header: a panel left open over the new page obscures it.
watch(() => route.fullPath, () => { openId.value = null; });

onBeforeUnmount(() => {
  document.removeEventListener('pointerdown', onDocumentPointerDown, true);
});
</script>

<template>
  <nav
    ref="rootRef"
    aria-label="Platform primary navigation"
    :data-testid="variant === 'header' ? 'header-primary-nav' : 'stacked-primary-nav'"
  >
    <!-- ============================ Header variant ============================ -->
    <ul
      v-if="variant === 'header'"
      class="flex flex-wrap items-center gap-1"
    >
      <li
        v-for="(group, index) in groups"
        :key="group.id"
        class="relative"
        :class="index >= INLINE_GROUP_COUNT ? 'hidden lg:block' : ''"
        :data-testid="`nav-group-item-${group.id}`"
      >
        <button
          :ref="(el) => setTriggerRef(group.id, el)"
          type="button"
          :aria-expanded="openId === group.id"
          :aria-controls="group.id"
          class="sv-focus-ring inline-flex min-h-sv-touch items-center gap-1 rounded-control px-3 py-2 text-sm font-medium text-white hover:bg-white/10"
          :class="activeGroupName === group.name ? 'bg-white/15 font-semibold' : ''"
          :data-testid="`nav-group-trigger-${group.id}`"
          @click="toggle(group.id)"
          @keydown="onTriggerKeydown($event, group.id)"
        >
          {{ group.name }}
          <span
            v-if="activeGroupName === group.name"
            class="sr-only"
          >(contains the current page)</span>
          <SvIconChevronDown
            aria-hidden="true"
            class="h-4 w-4 shrink-0"
          />
        </button>

        <ul
          v-if="openId === group.id"
          :id="group.id"
          class="absolute left-0 z-sv-popover mt-2 max-h-[70vh] w-max min-w-[14rem] max-w-[calc(100vw-2rem)] overflow-y-auto rounded-card border border-sv-border bg-sv-surface-raised py-1 shadow-overlay"
          :aria-label="group.name"
          @keydown="onPanelKeydown($event, group.id)"
        >
          <li
            v-for="item in group.items"
            :key="item.key"
          >
            <RouterLink
              v-if="!item.disabled && item.routeName"
              :to="{ name: item.routeName }"
              class="sv-focus-ring flex min-h-sv-touch items-center px-3 py-2 text-sm text-sv-text hover:bg-sv-surface-subtle"
              :class="isActive(item) ? 'font-semibold text-sv-text-heading' : ''"
              :aria-current="isActive(item) ? 'page' : undefined"
              :data-testid="`nav-link-${item.key}`"
              @click="onNavigate"
            >
              {{ item.label }}
            </RouterLink>

            <!--
              A gate-blocked entry. Visible because the authoritative map lists it, inert because it
              has no destination, and it names the exact gate rather than a vague "coming soon".
              `tabindex="0"` keeps it keyboard-discoverable: a user must be able to find out WHY it
              is unavailable, which is impossible if it cannot be reached.
            -->
            <span
              v-else
              role="link"
              aria-disabled="true"
              tabindex="0"
              class="sv-focus-ring flex min-h-sv-touch flex-col justify-center px-3 py-2 text-sm text-sv-disabled-fg"
              :data-testid="`nav-gated-${item.key}`"
            >
              <span>{{ item.label }}</span>
              <span
                v-if="item.disabledReason"
                class="text-xs"
                :data-testid="`nav-gate-reason-${item.key}`"
              >Unavailable — {{ item.disabledReason }}</span>
            </span>
          </li>
        </ul>
      </li>

      <!--
        Overflow: holds the tail groups below the DESKTOP floor (UI08-NAV-003).

        This said `xl` until Increment 10's browser proof found that `xl` does not exist in this
        Tailwind config — `tailwind.config` OVERRIDES `screens` to the two plan-mandated boundaries,
        `md` (tablet floor) and `lg` (desktop floor), precisely so no unmandated breakpoint can be
        used. `hidden xl:block` therefore compiled to a permanent `hidden`, and three of the eight
        contract groups were reachable only through "More" even at 1440px. `lg` is the desktop floor
        the plan defines, so the tail is inline on desktop and behind the disclosure on tablet.
      -->
      <li
        v-if="overflowGroups.length > 0"
        class="relative lg:hidden"
      >
        <button
          :ref="(el) => setTriggerRef('nav-group-overflow', el)"
          type="button"
          :aria-expanded="openId === 'nav-group-overflow'"
          aria-controls="nav-group-overflow"
          class="sv-focus-ring inline-flex min-h-sv-touch items-center gap-1 rounded-control px-3 py-2 text-sm font-medium text-white hover:bg-white/10"
          data-testid="nav-overflow-trigger"
          @click="toggle('nav-group-overflow')"
          @keydown="onTriggerKeydown($event, 'nav-group-overflow')"
        >
          More
          <SvIconChevronDown
            aria-hidden="true"
            class="h-4 w-4 shrink-0"
          />
        </button>

        <div
          v-if="openId === 'nav-group-overflow'"
          id="nav-group-overflow"
          class="absolute right-0 z-sv-popover mt-2 max-h-[70vh] w-max min-w-[14rem] max-w-[calc(100vw-2rem)] overflow-y-auto rounded-card border border-sv-border bg-sv-surface-raised py-1 shadow-overlay"
          data-testid="nav-overflow-panel"
          @keydown="onPanelKeydown($event, 'nav-group-overflow')"
        >
          <template
            v-for="group in overflowGroups"
            :key="`overflow-${group.id}`"
          >
            <p class="px-3 pb-1 pt-2 text-xs font-semibold uppercase tracking-wide text-sv-text-muted">
              {{ group.name }}
            </p>
            <ul :aria-label="group.name">
              <li
                v-for="item in group.items"
                :key="`overflow-${item.key}`"
              >
                <RouterLink
                  v-if="!item.disabled && item.routeName"
                  :to="{ name: item.routeName }"
                  class="sv-focus-ring flex min-h-sv-touch items-center px-3 py-2 text-sm text-sv-text hover:bg-sv-surface-subtle"
                  :class="isActive(item) ? 'font-semibold text-sv-text-heading' : ''"
                  :aria-current="isActive(item) ? 'page' : undefined"
                  :data-testid="`nav-overflow-link-${item.key}`"
                  @click="onNavigate"
                >
                  {{ item.label }}
                </RouterLink>
                <span
                  v-else
                  role="link"
                  aria-disabled="true"
                  tabindex="0"
                  class="sv-focus-ring flex min-h-sv-touch flex-col justify-center px-3 py-2 text-sm text-sv-disabled-fg"
                  :data-testid="`nav-overflow-gated-${item.key}`"
                >
                  <span>{{ item.label }}</span>
                  <span
                    v-if="item.disabledReason"
                    class="text-xs"
                  >Unavailable — {{ item.disabledReason }}</span>
                </span>
              </li>
            </ul>
          </template>
        </div>
      </li>
    </ul>

    <!-- ============================ Stacked variant (mobile drawer) ============================ -->
    <div
      v-else
      class="flex flex-col gap-4"
    >
      <section
        v-for="group in groups"
        :key="`stacked-${group.id}`"
        :aria-labelledby="`${group.id}-label`"
      >
        <p
          :id="`${group.id}-label`"
          class="px-3 pb-1 text-xs font-semibold uppercase tracking-wide text-sv-text-muted"
        >
          {{ group.name }}
        </p>
        <ul class="flex flex-col gap-1">
          <li
            v-for="item in group.items"
            :key="`stacked-${item.key}`"
          >
            <RouterLink
              v-if="!item.disabled && item.routeName"
              :to="{ name: item.routeName }"
              class="sv-focus-ring flex min-h-sv-touch items-center rounded-control px-3 py-2 text-sm text-sv-text hover:bg-sv-surface-subtle"
              :class="isActive(item) ? 'bg-sv-surface-subtle font-semibold text-sv-text-heading' : ''"
              :aria-current="isActive(item) ? 'page' : undefined"
              :data-testid="`nav-stacked-link-${item.key}`"
              @click="onNavigate"
            >
              {{ item.label }}
            </RouterLink>
            <span
              v-else
              role="link"
              aria-disabled="true"
              tabindex="0"
              class="sv-focus-ring flex min-h-sv-touch flex-col justify-center rounded-control px-3 py-2 text-sm text-sv-disabled-fg"
              :data-testid="`nav-stacked-gated-${item.key}`"
            >
              <span>{{ item.label }}</span>
              <span
                v-if="item.disabledReason"
                class="text-xs"
              >Unavailable — {{ item.disabledReason }}</span>
            </span>
          </li>
        </ul>
      </section>
    </div>
  </nav>
</template>

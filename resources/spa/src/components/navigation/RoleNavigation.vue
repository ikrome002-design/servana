<script setup lang="ts">
import { computed } from 'vue';
import { useRoute } from 'vue-router';
import PermissionGate from '@/components/auth/PermissionGate.vue';
import type { NavItem } from '@/navigation/roleNavigation';

/**
 * Renders a role's primary navigation (Phase 11, Plan §27.2). Presentational:
 * the caller supplies the items and chooses the variant. Visibility here is UX
 * only (PermissionGate) — backend authorization governs direct URL access.
 *
 *  - `live` items render a RouterLink to a real route, with aria-current on the
 *    active route.
 *  - `planned` items render a clearly-disabled, non-interactive entry tagged
 *    "Soon" with its owning phase — never a fake link (no dead routes).
 *  - items carrying a `permission` are wrapped in PermissionGate (hidden when
 *    the user lacks it).
 *
 * Colors are variant-aware so contrast meets WCAG AA in both placements: the
 * `header` variant sits on the dark brand-deep Super-Admin header (light text),
 * the `sidebar` variant uses adaptive light/dark tokens (ADR-009).
 */
const props = withDefaults(
  defineProps<{
    items: NavItem[];
    variant?: 'sidebar' | 'header';
  }>(),
  { variant: 'sidebar' },
);

const emit = defineEmits<{ navigate: [] }>();

const route = useRoute();
const isActive = (item: NavItem): boolean =>
  item.routeName !== undefined && route.name === item.routeName;

const listClass = computed(() =>
  props.variant === 'header'
    ? 'flex flex-wrap items-center gap-1'
    : 'flex flex-col gap-1',
);

const baseLink =
  'flex min-h-[44px] items-center rounded-control px-3 py-2 text-sm font-medium focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary';

function linkClass(item: NavItem): string {
  if (props.variant === 'header') {
    return `${baseLink} ${
      isActive(item) ? 'bg-white/15 font-semibold text-white' : 'text-white hover:bg-white/10'
    }`;
  }
  return `${baseLink} ${
    isActive(item) ? 'bg-surface-alt font-semibold text-heading' : 'text-text hover:bg-surface-alt'
  }`;
}

const plannedClass = computed(() => {
  const base =
    'flex min-h-[44px] items-center justify-between gap-2 rounded-control px-3 py-2 text-sm font-medium';
  return props.variant === 'header' ? `${base} text-white/70` : `${base} text-text-muted`;
});

const badgeClass = computed(() =>
  props.variant === 'header'
    ? 'rounded-full bg-white/20 px-2 py-0.5 text-[11px] font-semibold uppercase tracking-wide text-white'
    : 'rounded-full bg-surface-alt px-2 py-0.5 text-[11px] font-semibold uppercase tracking-wide text-text-muted',
);
</script>

<template>
  <ul :class="listClass">
    <template
      v-for="item in items"
      :key="item.key"
    >
      <PermissionGate
        v-if="item.permission"
        :permission="item.permission"
      >
        <li>
          <RouterLink
            v-if="item.availability === 'live' && item.routeName"
            :to="{ name: item.routeName }"
            :class="linkClass(item)"
            :aria-current="isActive(item) ? 'page' : undefined"
            @click="emit('navigate')"
          >
            {{ item.label }}
          </RouterLink>
          <span
            v-else
            :class="plannedClass"
            aria-disabled="true"
            :title="`Available in ${item.phase}`"
          >
            {{ item.label }}
            <span :class="badgeClass">Soon</span>
          </span>
        </li>
      </PermissionGate>

      <li v-else>
        <RouterLink
          v-if="item.availability === 'live' && item.routeName"
          :to="{ name: item.routeName }"
          :class="linkClass(item)"
          :aria-current="isActive(item) ? 'page' : undefined"
          @click="emit('navigate')"
        >
          {{ item.label }}
        </RouterLink>
        <span
          v-else
          :class="plannedClass"
          aria-disabled="true"
          :title="`Available in ${item.phase}`"
        >
          {{ item.label }}
          <span :class="badgeClass">Soon</span>
        </span>
      </li>
    </template>
  </ul>
</template>

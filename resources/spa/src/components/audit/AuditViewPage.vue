<script setup lang="ts">
/**
 * AuditViewPage — the shared composition of the five Audit Views (Phase UI-15; §12.4.6–§12.4.10).
 *
 * A tonal header, one-tap investigation presets, the filter rail and the evidence feed on the
 * left; on the right, the lens itself made explicit — which events this view selects, what is
 * masked, and what this account cannot do here. Desktop is asymmetric (the Billings/Dashboard
 * reference lesson); tablet and mobile stack the aside below the feed so evidence comes first.
 */
import { RouterLink } from 'vue-router';
import AuditEventFeed from '@/components/audit/AuditEventFeed.vue';
import AuditFilterPanel from '@/components/audit/AuditFilterPanel.vue';
import SvTonalPageHeader from '@/components/ui/SvTonalPageHeader.vue';
import { SvIconForbidden, SvIconLocked, SvIconSearch } from '@/design-system/icons';
import { useAuditRouteFilters } from '@/composables/useAuditRouteFilters';
import type { AuditDomain, AuditReviewViewKey } from '@/stores/auditEventStore';

export interface AuditViewPreset {
  label: string;
  severity?: string;
  flagged?: string;
}

withDefaults(defineProps<{
  idPrefix: string;
  eyebrow: string;
  title: string;
  description: string;
  domain?: AuditDomain;
  view?: AuditReviewViewKey;
  tone?: 'warm' | 'teal' | 'green';
  includes: string[];
  masking: string;
  boundaries: string[];
  presets?: AuditViewPreset[];
  emptyMessage: string;
  groupRepeats?: boolean;
}>(), { domain: 'general', view: undefined, tone: 'teal', presets: () => [], groupRepeats: false });

const { filters, apply } = useAuditRouteFilters();

function isActive(preset: AuditViewPreset): boolean {
  return (filters.value.severity ?? '') === (preset.severity ?? '') && (filters.value.flagged ?? '') === (preset.flagged ?? '');
}
</script>

<template>
  <section
    class="mx-auto max-w-7xl"
    :data-testid="`${idPrefix}-page`"
  >
    <SvTonalPageHeader
      :eyebrow="eyebrow"
      :title="title"
      :description="description"
      context="Assigned branch · read-only"
      :tone="tone"
    >
      <template #actions>
        <RouterLink
          :to="{ name: 'audit.audit-flags' }"
          class="sv-focus-ring inline-flex min-h-sv-touch items-center rounded-control bg-sv-brand px-4 text-sm font-bold text-sv-text-on-brand hover:bg-sv-brand-hover"
        >
          Open review queue
        </RouterLink>
      </template>
      <div
        v-if="presets.length > 0"
        class="flex flex-wrap gap-2"
        role="group"
        :aria-label="`${title} quick lenses`"
      >
        <button
          v-for="preset in presets"
          :key="preset.label"
          type="button"
          class="sv-preset sv-focus-ring inline-flex min-h-sv-touch items-center gap-1 rounded-pill border px-4 text-sm font-semibold transition-colors duration-sv-fast motion-reduce:transition-none"
          :aria-pressed="isActive(preset)"
          @click="apply({ severity: preset.severity ?? '', flagged: preset.flagged ?? '' })"
        >
          <SvIconSearch
            aria-hidden="true"
            class="h-4 w-4"
          />{{ preset.label }}
        </button>
      </div>
    </SvTonalPageHeader>

    <div class="grid grid-cols-[minmax(0,1fr)] gap-5 lg:grid-cols-[minmax(0,1fr)_20rem]">
      <div class="flex min-w-0 flex-col gap-4">
        <AuditFilterPanel :id-prefix="idPrefix" />
        <AuditEventFeed
          :domain="domain"
          :view="view"
          :label="`${title} events`"
          :empty-message="emptyMessage"
          :group-repeats="groupRepeats"
        />
      </div>

      <aside
        class="flex flex-col gap-4"
        :aria-label="`About ${title}`"
      >
        <section class="rounded-card border border-sv-border bg-sv-surface-raised p-5 shadow-card">
          <h2 class="font-display text-base font-bold text-sv-text-heading">
            What this view selects
          </h2>
          <ul class="mt-3 flex flex-col gap-2 text-sm text-sv-text-secondary">
            <li
              v-for="item in includes"
              :key="item"
              class="flex gap-2"
            >
              <span
                aria-hidden="true"
                class="sv-dot mt-2 h-1.5 w-1.5 shrink-0 rounded-full"
              />{{ item }}
            </li>
          </ul>
          <p class="mt-3 text-xs text-sv-text-muted">
            The selection is made by the server, inside your assigned branch.
          </p>
        </section>
        <section class="rounded-card border border-sv-border bg-sv-surface-raised p-5 shadow-card">
          <h2 class="flex items-center gap-2 font-display text-base font-bold text-sv-text-heading">
            <SvIconLocked
              aria-hidden="true"
              class="h-4 w-4"
            />Masking
          </h2>
          <p class="mt-2 text-sm text-sv-text-secondary">
            {{ masking }}
          </p>
        </section>
        <section class="sv-boundary rounded-card border p-5">
          <h2 class="flex items-center gap-2 font-display text-base font-bold text-sv-text-heading">
            <SvIconForbidden
              aria-hidden="true"
              class="h-4 w-4"
            />Not available to Audit
          </h2>
          <ul class="mt-2 flex flex-col gap-1.5 text-sm text-sv-text-secondary">
            <li
              v-for="item in boundaries"
              :key="item"
            >
              {{ item }}
            </li>
          </ul>
        </section>
      </aside>
    </div>
  </section>
</template>

<style scoped>
.sv-preset {
  border-color: color-mix(in srgb, var(--sv-color-brand-secondary) 35%, var(--sv-color-border-default));
  background: var(--sv-color-surface-raised);
  color: var(--sv-color-text-primary);
}
.sv-preset[aria-pressed='true'] {
  border-color: var(--sv-color-brand-secondary);
  background: color-mix(in srgb, var(--sv-color-brand-secondary) 16%, var(--sv-color-surface-raised));
  color: var(--sv-color-text-heading);
}
.sv-dot { background: var(--sv-color-brand-secondary); }
.sv-boundary {
  border-color: color-mix(in srgb, var(--sv-color-text-heading) 18%, var(--sv-color-border-default));
  background: color-mix(in srgb, var(--sv-color-surface-subtle) 70%, var(--sv-color-surface-raised));
}
</style>

<script setup lang="ts">
/**
 * SvKpiCard — one server-supplied figure with its label and meaning (COR-UI08-UI12-VIS-001).
 *
 * The value arrives already decided: either as `value` or through the default slot (for SvMoney
 * and other formatted figures). The card never computes, sums or defaults a number — an unknown
 * value renders the caller's `unknownLabel`, never a fabricated zero.
 *
 * `to` turns the card's link into navigation to the page that can act on the figure; the card
 * itself is not clickable (a whole-card target would swallow the link's name).
 */
import type { Component } from 'vue';
import { RouterLink, type RouteLocationRaw } from 'vue-router';
import { SvIconChevronRight } from '@/design-system/icons';

withDefaults(
  defineProps<{
    label: string;
    value?: string | number | null;
    hint?: string;
    tone?: 'warm' | 'teal' | 'green' | 'sun' | 'alert' | 'neutral';
    icon?: Component | null;
    to?: RouteLocationRaw | null;
    linkLabel?: string;
    /** Rendered when `value` is null/undefined and no slot is supplied. */
    unknownLabel?: string;
  }>(),
  {
    value: undefined,
    hint: undefined,
    tone: 'warm',
    icon: null,
    to: null,
    linkLabel: 'Open',
    unknownLabel: '—',
  },
);
</script>

<template>
  <div
    class="sv-r-kpi"
    :class="`sv-r-tone-${tone}`"
    data-testid="sv-kpi-card"
  >
    <div class="sv-r-kpi__top">
      <p class="sv-r-kpi__label">
        {{ label }}
      </p>
      <span
        v-if="icon"
        aria-hidden="true"
        class="sv-r-icon"
      >
        <component :is="icon" />
      </span>
    </div>
    <p class="sv-r-kpi__value">
      <slot>{{ value ?? unknownLabel }}</slot>
    </p>
    <p
      v-if="hint"
      class="sv-r-kpi__hint"
    >
      {{ hint }}
    </p>
    <slot name="meta" />
    <RouterLink
      v-if="to"
      :to="to"
      class="sv-r-link sv-focus-ring mt-auto self-start rounded-control"
    >
      {{ linkLabel }}
      <SvIconChevronRight
        aria-hidden="true"
        class="h-4 w-4"
      />
    </RouterLink>
  </div>
</template>

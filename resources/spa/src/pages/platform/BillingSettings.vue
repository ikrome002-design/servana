<script setup lang="ts">
import { computed, nextTick, ref } from 'vue';
import { useCan } from '@/composables/useCan';
import BillingSettingsSection from '@/pages/platform/billing/BillingSettingsSection.vue';
import GeneralSettingsSection from '@/pages/platform/billing/GeneralSettingsSection.vue';
import PlanEntitlementsSection from '@/pages/platform/billing/PlanEntitlementsSection.vue';
import PlanPricesSection from '@/pages/platform/billing/PlanPricesSection.vue';
import PlatformFeeConfigSection from '@/pages/platform/billing/PlatformFeeConfigSection.vue';
import PreferredFeeRulesSection from '@/pages/platform/billing/PreferredFeeRulesSection.vue';
import SubscriptionPlansSection from '@/pages/platform/billing/SubscriptionPlansSection.vue';
import type { SubscriptionPlan } from '@/stores/subscriptionPlanStore';

// Phase 20A — the single genuine platform screen (Plan §27.1, §47, §50). One coherent
// surface with accessible tabs for general settings, billing settings, plans, prices,
// entitlements and the preferred-personnel fee rule. Each tab is gated by the resolved
// permission (UX only — the API enforces authorization, MFA and step-up). Registration
// monitoring and plan management are Phase 20B and are NOT rendered here.
const { can } = useCan();

interface TabDef {
  key: string;
  label: string;
  permission: string;
}

const allTabs: TabDef[] = [
  { key: 'general', label: 'General settings', permission: 'platform.settings.view' },
  { key: 'billing', label: 'Billing settings', permission: 'platform.billing_settings.view' },
  { key: 'plans', label: 'Plans', permission: 'platform.plan.view' },
  { key: 'prices', label: 'Prices', permission: 'platform.plan.view' },
  { key: 'entitlements', label: 'Entitlements', permission: 'platform.plan.view' },
  { key: 'fees', label: 'Preferred-personnel fee', permission: 'platform.preferred_personnel_fee.manage' },
  { key: 'platform-fees', label: 'Platform fees', permission: 'platform.platform_fee.configure' },
];

// Only tabs the user can view are rendered — a denied control is absent, never disabled.
const tabs = computed<TabDef[]>(() => allTabs.filter((t) => can(t.permission)));

const activeKey = ref<string>('');
const selectedPlan = ref<SubscriptionPlan | null>(null);
const tabRefs = ref<Record<string, HTMLButtonElement | null>>({});

// Default to the first visible tab.
const currentKey = computed<string>(() => {
  if (activeKey.value !== '' && tabs.value.some((t) => t.key === activeKey.value)) return activeKey.value;
  return tabs.value[0]?.key ?? '';
});

function select(key: string): void {
  activeKey.value = key;
}

function setTabRef(key: string, el: unknown): void {
  tabRefs.value[key] = (el as HTMLButtonElement | null) ?? null;
}

async function focusTab(key: string): Promise<void> {
  select(key);
  await nextTick();
  tabRefs.value[key]?.focus();
}

function onKeydown(event: KeyboardEvent, index: number): void {
  const list = tabs.value;
  if (list.length === 0) return;
  let next: number;
  if (event.key === 'ArrowRight' || event.key === 'ArrowDown') next = (index + 1) % list.length;
  else if (event.key === 'ArrowLeft' || event.key === 'ArrowUp') next = (index - 1 + list.length) % list.length;
  else if (event.key === 'Home') next = 0;
  else if (event.key === 'End') next = list.length - 1;
  else return;
  event.preventDefault();
  void focusTab(list[next].key);
}

// When a plan is selected from the Plans tab, jump to Prices with it in context.
function onSelectPlan(plan: SubscriptionPlan): void {
  selectedPlan.value = plan;
  if (can('platform.plan.view')) select('prices');
}
</script>

<template>
  <div
    class="mx-auto w-full max-w-4xl px-4 py-6"
    data-testid="billing-screen"
  >
    <header class="mb-6">
      <h1 class="font-display text-2xl font-bold text-heading">
        Billing settings
      </h1>
      <p class="mt-1 text-sm text-text-muted">
        Platform billing configuration: settings, subscription plans, effective-dated prices,
        entitlements and the preferred-personnel fee rule. Sensitive changes require a fresh
        step-up.
      </p>
    </header>

    <div
      v-if="tabs.length === 0"
      class="rounded-card border border-border bg-surface p-6 text-sm text-text-muted"
      role="note"
    >
      You do not have access to any billing configuration.
    </div>

    <template v-else>
      <div
        role="tablist"
        aria-label="Billing configuration"
        class="flex flex-wrap gap-1 border-b border-border"
      >
        <button
          v-for="(tab, index) in tabs"
          :id="`tab-${tab.key}`"
          :key="tab.key"
          :ref="(el) => setTabRef(tab.key, el)"
          type="button"
          role="tab"
          :aria-selected="currentKey === tab.key"
          :aria-controls="`panel-${tab.key}`"
          :tabindex="currentKey === tab.key ? 0 : -1"
          class="min-h-[44px] rounded-t-control px-4 py-2 text-sm font-semibold focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary"
          :class="currentKey === tab.key
            ? 'border-b-2 border-primary text-heading'
            : 'text-text-muted hover:text-text'"
          @click="select(tab.key)"
          @keydown="onKeydown($event, index)"
        >
          {{ tab.label }}
        </button>
      </div>

      <div
        v-for="tab in tabs"
        v-show="currentKey === tab.key"
        :id="`panel-${tab.key}`"
        :key="tab.key"
        role="tabpanel"
        :aria-labelledby="`tab-${tab.key}`"
        tabindex="0"
        class="py-6 focus-visible:outline-none"
      >
        <GeneralSettingsSection v-if="tab.key === 'general' && currentKey === 'general'" />
        <BillingSettingsSection v-else-if="tab.key === 'billing' && currentKey === 'billing'" />
        <SubscriptionPlansSection
          v-else-if="tab.key === 'plans' && currentKey === 'plans'"
          @select="onSelectPlan"
        />
        <PlanPricesSection
          v-else-if="tab.key === 'prices' && currentKey === 'prices'"
          :plan="selectedPlan"
        />
        <PlanEntitlementsSection
          v-else-if="tab.key === 'entitlements' && currentKey === 'entitlements'"
          :plan="selectedPlan"
        />
        <PreferredFeeRulesSection v-else-if="tab.key === 'fees' && currentKey === 'fees'" />
        <PlatformFeeConfigSection v-else-if="tab.key === 'platform-fees' && currentKey === 'platform-fees'" />
      </div>
    </template>
  </div>
</template>

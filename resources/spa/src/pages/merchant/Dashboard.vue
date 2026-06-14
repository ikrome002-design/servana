<script setup lang="ts">
import { computed } from 'vue';
import SvCard from '@/components/ui/SvCard.vue';
import { useAuthStore } from '@/stores/authStore';
import { useMerchantStore } from '@/stores/merchantStore';

// Merchant dashboard shell (Plan §27 Phase 6). Shell only — the operational
// widgets/reports arrive in later phases. Reaching this page means the merchant
// is active (EnsureMerchantActive on the API; requiresActiveMerchant guard).
const auth = useAuthStore();
const merchant = useMerchantStore();

const businessName = computed(() => merchant.name ?? 'your business');
const sections = ['Overview', 'Branches', 'Staff', 'Reports'];
</script>

<template>
  <section class="p-4 md:p-6">
    <h1 class="font-display text-2xl font-bold text-brand-deep">
      Welcome, {{ auth.user?.name }}
    </h1>
    <p class="mt-1 text-text-muted">
      {{ businessName }} is set up and ready. Your full dashboard arrives soon.
    </p>

    <div class="mt-6 grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
      <SvCard
        v-for="name in sections"
        :key="name"
        as="article"
        padding="md"
      >
        <h2 class="font-display text-base font-semibold text-brand-deep">{{ name }}</h2>
        <p class="mt-1 text-sm text-text-muted">Coming soon.</p>
      </SvCard>
    </div>
  </section>
</template>

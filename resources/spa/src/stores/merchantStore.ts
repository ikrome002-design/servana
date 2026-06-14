import { defineStore } from 'pinia';
import { computed, ref } from 'vue';
import type { Merchant } from '@/types/models';

/**
 * Current merchant tenant (Plan §6.2, §8.1). Populated from the /me bootstrap
 * by authStore. UX state only — the API enforces tenant access.
 */
export const useMerchantStore = defineStore('merchant', () => {
  const merchant = ref<Merchant | null>(null);

  function setMerchant(next: Merchant | null): void {
    merchant.value = next;
  }

  function $reset(): void {
    merchant.value = null;
  }

  const isActive = (): boolean => merchant.value?.status === 'active';
  const isPendingSetup = (): boolean => merchant.value?.status === 'pending_setup';
  const name = computed(() => merchant.value?.name ?? null);

  return { merchant, name, setMerchant, isActive, isPendingSetup, $reset };
});

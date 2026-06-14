import { defineStore } from 'pinia';
import { ref } from 'vue';
import type { Merchant } from '@/types/models';

// Phase 4: typed structure only. Real tenant resolution lands in Phase 6.
export const useMerchantStore = defineStore('merchant', () => {
  const merchant = ref<Merchant | null>(null);

  function $reset(): void {
    merchant.value = null;
  }

  const isActive = (): boolean => merchant.value?.status === 'active';

  return { merchant, isActive, $reset };
});

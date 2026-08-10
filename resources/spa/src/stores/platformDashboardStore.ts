import { defineStore } from 'pinia';
import { ref } from 'vue';
import { apiClient } from '@/services/apiClient';
import type { paths } from '@/types/generated/api';

/**
 * Super Administrator governance dashboard (Phase UI-08, contract page §5.4.1).
 *
 * ONE server-side aggregate read. The browser deliberately computes NOTHING here: every other
 * platform endpoint is paginated, so a client-side total would be the total of page one — a false
 * figure on the screen the platform owner governs from.
 *
 * Each section carries its own `availability`. A section blocked by an external gate arrives with
 * null values and the exact gate, and this store passes that through unchanged. It never
 * substitutes a zero, because on a governance screen a fabricated zero reads as good news.
 */
type DashboardResponse = paths['/api/v1/platform/dashboard']['get']['responses'][200]['content']['application/json'];

export type PlatformDashboard = DashboardResponse['data'];
export type PlatformDashboardMeta = DashboardResponse['meta'];

/** A section is either reporting real figures or naming the gate that blocks it. */
export type SectionAvailability = 'available' | 'disabled_by_gate';

export const usePlatformDashboardStore = defineStore('platformDashboard', () => {
  const dashboard = ref<PlatformDashboard | null>(null);
  const meta = ref<PlatformDashboardMeta | null>(null);
  const loading = ref(false);
  const error = ref<string | null>(null);
  const lastRefreshed = ref<string | null>(null);

  let sequence = 0;
  const isCurrent = (token: number): boolean => token === sequence;

  function $reset(): void {
    dashboard.value = null;
    meta.value = null;
    loading.value = false;
    error.value = null;
    lastRefreshed.value = null;
    sequence += 1;
  }

  async function load(): Promise<void> {
    const token = ++sequence;
    loading.value = true;
    error.value = null;

    try {
      const { data } = await apiClient.get<DashboardResponse>('/platform/dashboard');
      if (!isCurrent(token)) return;
      dashboard.value = data.data;
      meta.value = data.meta;
      lastRefreshed.value = new Date().toISOString();
    } catch {
      if (isCurrent(token)) error.value = 'Unable to load the platform dashboard.';
    } finally {
      if (isCurrent(token)) loading.value = false;
    }
  }

  return { dashboard, meta, loading, error, lastRefreshed, $reset, load };
});

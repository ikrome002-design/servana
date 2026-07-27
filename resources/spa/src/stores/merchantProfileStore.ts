import { defineStore } from 'pinia';
import { ref } from 'vue';
import { apiClient } from '@/services/apiClient';
import type { MerchantProfile, MerchantProfileUpdate } from '@/types/models';

/**
 * Merchant business profile (REM-SCR-002A; Plan §27.3 Merchant Administrator "merchant profile").
 *
 * UX state only — the backend (`merchant.profile.view` / `merchant.profile.update` +
 * MerchantProfilePolicy + EnsureBillingMutable) is the authorization boundary. The route takes no
 * merchant identifier: the tenant is resolved from the caller's membership.
 */
export const useMerchantProfileStore = defineStore('merchantProfile', () => {
  const profile = ref<MerchantProfile | null>(null);
  const loading = ref(false);
  const saving = ref(false);
  /** Field-scoped validation errors from the API envelope (`error.fields`). */
  const fieldErrors = ref<Record<string, string[]>>({});

  function $reset(): void {
    profile.value = null;
    loading.value = false;
    saving.value = false;
    fieldErrors.value = {};
  }

  async function fetchProfile(): Promise<void> {
    loading.value = true;
    try {
      const { data } = await apiClient.get<{ data: MerchantProfile }>('/merchant/profile');
      profile.value = data.data;
    } finally {
      loading.value = false;
    }
  }

  /** PATCH the allowlisted fields. Throws on failure so the screen can surface the outcome. */
  async function updateProfile(payload: MerchantProfileUpdate): Promise<void> {
    saving.value = true;
    fieldErrors.value = {};
    try {
      const { data } = await apiClient.patch<{ data: MerchantProfile }>(
        '/merchant/profile',
        payload,
      );
      profile.value = data.data;
    } catch (error: unknown) {
      fieldErrors.value = extractFieldErrors(error);
      throw error;
    } finally {
      saving.value = false;
    }
  }

  /**
   * A short-lived signed link for the current logo, through the existing Phase 10F endpoint.
   * The URL is NEVER persisted in the store — it expires, and holding it would outlive its
   * authorization.
   */
  async function logoUrl(): Promise<string | null> {
    const logo = profile.value?.logo;
    if (!logo) return null;

    const { data } = await apiClient.post<{ data: { url: string; expires_at: string } }>(
      `/files/${logo.id}/download-link`,
    );
    return data.data.url;
  }

  return { profile, loading, saving, fieldErrors, fetchProfile, updateProfile, logoUrl, $reset };
});

/** Pull `error.fields` out of the API error envelope without assuming a client shape. */
function extractFieldErrors(error: unknown): Record<string, string[]> {
  const fields = (error as { response?: { data?: { error?: { fields?: unknown } } } })?.response
    ?.data?.error?.fields;

  if (!fields || typeof fields !== 'object' || Array.isArray(fields)) return {};

  const out: Record<string, string[]> = {};
  for (const [key, value] of Object.entries(fields as Record<string, unknown>)) {
    if (Array.isArray(value)) out[key] = value.map(String);
    else if (typeof value === 'string') out[key] = [value];
  }
  return out;
}

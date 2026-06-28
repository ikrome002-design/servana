import { defineStore } from 'pinia';
import { ref } from 'vue';
import { apiClient } from '@/services/apiClient';
import type { Service, ServiceCategory, ServiceEligibility } from '@/types/models';

/**
 * Service catalogue + personnel eligibility (Scope §catalogue/§39, Phase 15A).
 * UX state only — the API (ServicePolicy / personnel.eligibility.manage +
 * EnsureBranchScope) is the authorization and isolation boundary.
 */
export const useCatalogueStore = defineStore('catalogue', () => {
  const services = ref<Service[]>([]);
  const categories = ref<ServiceCategory[]>([]);
  const eligibility = ref<ServiceEligibility[]>([]);
  const loading = ref(false);
  const error = ref<string | null>(null);

  function $reset(): void {
    services.value = [];
    categories.value = [];
    eligibility.value = [];
    loading.value = false;
    error.value = null;
  }

  async function fetchCategories(): Promise<void> {
    const { data } = await apiClient.get<{ data: ServiceCategory[] }>('/service-categories');
    categories.value = data.data;
  }

  async function createCategory(payload: { name: string; sort_order?: number }): Promise<ServiceCategory> {
    const { data } = await apiClient.post<{ data: ServiceCategory }>('/service-categories', payload);
    categories.value = [...categories.value, data.data];
    return data.data;
  }

  async function fetchServices(params: { status?: string; category_id?: string; q?: string } = {}): Promise<void> {
    loading.value = true;
    error.value = null;
    try {
      const { data } = await apiClient.get<{ data: Service[] }>('/services', { params });
      services.value = data.data;
    } catch {
      error.value = 'Unable to load services.';
    } finally {
      loading.value = false;
    }
  }

  async function fetchService(id: string): Promise<Service> {
    const { data } = await apiClient.get<{ data: Service }>(`/services/${id}`);
    return data.data;
  }

  async function createService(payload: Record<string, unknown>): Promise<Service> {
    const { data } = await apiClient.post<{ data: Service }>('/services', payload);
    services.value = [data.data, ...services.value];
    return data.data;
  }

  async function updateService(id: string, payload: Record<string, unknown>): Promise<Service> {
    const { data } = await apiClient.patch<{ data: Service }>(`/services/${id}`, payload);
    services.value = services.value.map((s) => (s.id === id ? data.data : s));
    return data.data;
  }

  async function archiveService(id: string): Promise<Service> {
    const { data } = await apiClient.post<{ data: Service }>(`/services/${id}/archive`);
    services.value = services.value.map((s) => (s.id === id ? data.data : s));
    return data.data;
  }

  async function fetchEligibility(serviceId: string): Promise<void> {
    const { data } = await apiClient.get<{ data: ServiceEligibility[] }>(`/services/${serviceId}/eligibility`);
    eligibility.value = data.data;
  }

  async function assignEligibility(serviceId: string, staffProfileId: string): Promise<void> {
    await apiClient.post(`/services/${serviceId}/eligibility`, { staff_profile_id: staffProfileId });
    await fetchEligibility(serviceId);
  }

  async function revokeEligibility(serviceId: string, staffProfileId: string): Promise<void> {
    await apiClient.delete(`/services/${serviceId}/eligibility/${staffProfileId}`);
    await fetchEligibility(serviceId);
  }

  return {
    services,
    categories,
    eligibility,
    loading,
    error,
    fetchCategories,
    createCategory,
    fetchServices,
    fetchService,
    createService,
    updateService,
    archiveService,
    fetchEligibility,
    assignEligibility,
    revokeEligibility,
    $reset,
  };
});

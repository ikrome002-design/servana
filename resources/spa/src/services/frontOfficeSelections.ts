import { apiClient } from '@/services/apiClient';
import type { ServiceEligibility } from '@/types/models';

/**
 * Front Office operational selection reads (LOCAL-UAT-001, LUAT-D01).
 *
 * The walk-in, appointment and queue screens choose a service and eligible personnel through these
 * narrow reads, never through the Branch Manager catalogue (`/services`, gated by `service.view`).
 * The server maps each `purpose` to the existing permission of that one workflow; a choice reserves
 * nothing — every create/assign/transfer re-checks eligibility and availability on submission.
 */
export type FrontOfficeServicePurpose = 'walk_in' | 'appointment';

export type FrontOfficePersonnelPurpose =
  | FrontOfficeServicePurpose
  | 'appointment_assign'
  | 'appointment_transfer'
  | 'queue_assign'
  | 'queue_transfer';

/** A bookable service as the picker needs it — no price or catalogue administration fields. */
export interface FrontOfficeServiceOption {
  id: string;
  name: string;
  duration_minutes: number;
}

export async function fetchServiceOptions(
  purpose: FrontOfficeServicePurpose,
  signal?: AbortSignal,
): Promise<FrontOfficeServiceOption[]> {
  return fetchAllOptions<FrontOfficeServiceOption>('/front-office/service-options', purpose, signal);
}

/** Active, employed personnel with active eligibility for one active service of the acting branch. */
export async function fetchPersonnelOptions(
  serviceId: string,
  purpose: FrontOfficePersonnelPurpose,
  signal?: AbortSignal,
): Promise<ServiceEligibility[]> {
  return fetchAllOptions<ServiceEligibility>(
    `/front-office/services/${encodeURIComponent(serviceId)}/personnel-options`, purpose, signal,
  );
}

/** Keep the picker complete while every server response stays bounded (Plan §23). */
async function fetchAllOptions<T>(url: string, purpose: string, signal?: AbortSignal): Promise<T[]> {
  const options: T[] = [];
  let page = 1;
  let lastPage: number;

  do {
    const { data } = await apiClient.get<{ data: T[]; meta?: { last_page: number } }>(url, {
      params: { purpose, ...(page > 1 ? { page } : {}) },
      signal,
    });
    options.push(...data.data);
    // Existing single-page fixture adapters carry only `data`; real paginated responses carry meta.
    lastPage = data.meta?.last_page ?? page;
    page++;
  } while (page <= lastPage);

  return options;
}

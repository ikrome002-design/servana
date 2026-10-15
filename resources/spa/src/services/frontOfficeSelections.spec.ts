import { beforeEach, describe, expect, it, vi } from 'vitest';

const get = vi.fn();
vi.mock('@/services/apiClient', () => ({ apiClient: { get: (...args: unknown[]) => get(...args) } }));

import { fetchPersonnelOptions, fetchServiceOptions } from '@/services/frontOfficeSelections';

// LOCAL-UAT-001, LUAT-D01: the Front Office pickers read the narrow selection endpoints with the
// purpose of their own workflow — never the Branch Manager catalogue (`/services`, `service.view`).
describe('front office selection client', () => {
  beforeEach(() => {
    get.mockReset();
    get.mockResolvedValue({ data: { data: [] } });
  });

  it('reads bookable services for the named purpose', async () => {
    await fetchServiceOptions('walk_in');
    expect(get).toHaveBeenCalledWith('/front-office/service-options', { params: { purpose: 'walk_in' }, signal: undefined });
  });

  it('reads eligible personnel for one service and one purpose', async () => {
    const controller = new AbortController();
    await fetchPersonnelOptions('01SERVICEULID0000000000000', 'queue_transfer', controller.signal);
    expect(get).toHaveBeenCalledWith('/front-office/services/01SERVICEULID0000000000000/personnel-options', {
      params: { purpose: 'queue_transfer' },
      signal: controller.signal,
    });
  });

  it('never calls the catalogue reads', async () => {
    await fetchServiceOptions('appointment');
    await fetchPersonnelOptions('01SERVICEULID0000000000000', 'appointment_assign');
    for (const [url] of get.mock.calls) {
      expect(String(url)).not.toMatch(/^\/services/);
    }
  });

  it('collects every service page instead of silently truncating the picker', async () => {
    const first = { id: 'one', name: 'First', duration_minutes: 30 };
    const second = { id: 'two', name: 'Second', duration_minutes: 60 };
    get.mockResolvedValueOnce({ data: { data: [first], meta: { current_page: 1, last_page: 2 } } });
    get.mockResolvedValueOnce({ data: { data: [second], meta: { current_page: 2, last_page: 2 } } });

    await expect(fetchServiceOptions('walk_in')).resolves.toEqual([first, second]);
    expect(get).toHaveBeenNthCalledWith(2, '/front-office/service-options', {
      params: { purpose: 'walk_in', page: 2 }, signal: undefined,
    });
  });

  it('collects every eligible-personnel page with the same purpose and cancellation signal', async () => {
    const signal = new AbortController().signal;
    const first = { staff_profile_id: 'one', staff_name: 'First', active: true };
    const second = { staff_profile_id: 'two', staff_name: 'Second', active: true };
    get.mockResolvedValueOnce({ data: { data: [first], meta: { current_page: 1, last_page: 2 } } });
    get.mockResolvedValueOnce({ data: { data: [second], meta: { current_page: 2, last_page: 2 } } });

    await expect(fetchPersonnelOptions('service', 'queue_transfer', signal)).resolves.toEqual([first, second]);
    expect(get).toHaveBeenNthCalledWith(2, '/front-office/services/service/personnel-options', {
      params: { purpose: 'queue_transfer', page: 2 }, signal,
    });
  });

  it('rejects a failed later page instead of returning an incomplete set of choices', async () => {
    get.mockResolvedValueOnce({ data: { data: [{ id: 'one' }], meta: { current_page: 1, last_page: 2 } } });
    get.mockRejectedValueOnce(new Error('second page unavailable'));

    await expect(fetchServiceOptions('appointment')).rejects.toThrow('second page unavailable');
  });
});

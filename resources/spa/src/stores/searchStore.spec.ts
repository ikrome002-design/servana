import { AxiosError, AxiosHeaders } from 'axios';
import { createPinia, setActivePinia } from 'pinia';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { apiClient } from '@/services/apiClient';
import { useAuthStore } from '@/stores/authStore';
import type { SearchResult } from '@/stores/searchScope';
import { useSearchStore } from '@/stores/searchStore';

vi.mock('@/services/apiClient', () => ({
  apiClient: { get: vi.fn(), post: vi.fn() },
}));

const get = vi.mocked(apiClient.get);

/** A full number the store must never see, because the API never sends one. */
const FULL_PHONE = '+254712345678';

function result(overrides: Partial<SearchResult> = {}): SearchResult {
  return {
    type: 'client',
    type_label: 'Client',
    ulid: '01HZZCLIENT0000000000000001',
    title: 'Amina Wanjiku',
    subtitle: null,
    snippet: null,
    status: 'active',
    date: '2026-07-25T10:00:00+00:00',
    amount: null,
    route: { name: 'front-office.clients.detail', id: '01HZZCLIENT0000000000000001' },
    branch: { ulid: '01HZZBRANCH0000000000000001', name: 'Westlands Branch' },
    ...overrides,
  };
}

function axios429(): AxiosError {
  const error = new AxiosError('Too Many Requests');
  error.response = {
    status: 429,
    statusText: 'Too Many Requests',
    data: { error: { code: 'rate_limited', message: 'Slow down.' } },
    headers: {},
    config: { headers: new AxiosHeaders() },
  };

  return error;
}

function axiosStatus(status: number, code: string): AxiosError {
  const error = new AxiosError('Failed');
  error.response = {
    status,
    statusText: 'Failed',
    data: { error: { code, message: 'Nope.' } },
    headers: {},
    config: { headers: new AxiosHeaders() },
  };

  return error;
}

describe('searchStore (Phase 22)', () => {
  beforeEach(() => {
    setActivePinia(createPinia());
    vi.clearAllMocks();
    localStorage.clear();
    sessionStorage.clear();
  });

  /*
   |--------------------------------------------------------------------------
   | The request allowlist (search-security.md §2)
   |--------------------------------------------------------------------------
   */

  it('sends only the trimmed term by default', async () => {
    get.mockResolvedValue({ data: { data: [result()] } });
    const store = useSearchStore();

    await store.search('  Amina  ');

    expect(get).toHaveBeenCalledWith('/search', { params: { q: 'Amina' } });
  });

  it('sends types, sort and limit only when supplied', async () => {
    get.mockResolvedValue({ data: { data: [] } });
    const store = useSearchStore();

    await store.search('Amina', { types: ['client', 'invoice'], sort: 'recent', limit: 10 });

    expect(get).toHaveBeenCalledWith('/search', {
      params: { q: 'Amina', types: ['client', 'invoice'], sort: 'recent', limit: 10 },
    });
  });

  it('never sends a scope, permission or engine parameter', async () => {
    get.mockResolvedValue({ data: { data: [] } });
    const store = useSearchStore();

    await store.search('Amina', { types: ['client'], sort: 'relevance', limit: 5 });

    const params = get.mock.calls[0]?.[1]?.params as Record<string, unknown>;

    for (const forbidden of [
      'merchant_id', 'merchant_ulid', 'branch_id', 'branch_ids',
      'staff_profile_id', 'staff_profile_ulid',
      'permission', 'permissions', 'role',
      'filter', 'filters', 'raw_filter', 'index', 'api_key',
      'include_sensitive', 'include_phone', 'include_email',
      'export', 'download', 'print', 'copy',
    ]) {
      expect(params).not.toHaveProperty(forbidden);
    }

    expect(Object.keys(params).sort()).toEqual(['limit', 'q', 'sort', 'types']);
  });

  it('does not call the API for a term shorter than two characters', async () => {
    const store = useSearchStore();

    await store.search('a');

    expect(get).not.toHaveBeenCalled();
    expect(store.results).toEqual([]);
  });

  it('clears held results when the term drops below the minimum', async () => {
    get.mockResolvedValue({ data: { data: [result()] } });
    const store = useSearchStore();

    await store.search('Amina');
    expect(store.results).toHaveLength(1);

    await store.search('a');
    expect(store.results).toEqual([]);
    expect(store.hasSearched).toBe(false);
  });

  /*
   |--------------------------------------------------------------------------
   | Outcomes
   |--------------------------------------------------------------------------
   */

  it('exposes results and groups them by type in server order', async () => {
    get.mockResolvedValue({
      data: {
        data: [
          result(),
          result({ type: 'invoice', type_label: 'Invoice', ulid: 'INV1', title: 'INV-000123' }),
          result({ ulid: 'CLIENT2', title: 'Amina Otieno' }),
        ],
      },
    });
    const store = useSearchStore();

    await store.search('Amina');

    expect(store.groupedResults.map((g) => g.type)).toEqual(['client', 'invoice']);
    expect(store.groupedResults[0]?.items).toHaveLength(2);
    expect(store.groupedResults[1]?.label).toBe('Invoice');
  });

  it('reports an empty search distinctly from an untouched one', async () => {
    get.mockResolvedValue({ data: { data: [] } });
    const store = useSearchStore();

    expect(store.isEmpty).toBe(false);

    await store.search('Zzzz');

    expect(store.isEmpty).toBe(true);
    expect(store.failure).toBe('none');
  });

  it('classifies a rate limit distinctly', async () => {
    get.mockRejectedValue(axios429());
    const store = useSearchStore();

    await store.search('Amina');

    expect(store.failure).toBe('rate_limited');
    expect(store.results).toEqual([]);
    expect(store.loading).toBe(false);
  });

  it('classifies a forbidden response distinctly', async () => {
    get.mockRejectedValue(axiosStatus(403, 'forbidden'));
    const store = useSearchStore();

    await store.search('Amina');

    expect(store.failure).toBe('forbidden');
  });

  it('classifies any other failure as a generic error', async () => {
    get.mockRejectedValue(axiosStatus(500, 'internal_error'));
    const store = useSearchStore();

    await store.search('Amina');

    expect(store.failure).toBe('error');
  });

  it('clears loading and results on failure', async () => {
    get.mockResolvedValueOnce({ data: { data: [result()] } });
    const store = useSearchStore();
    await store.search('Amina');
    expect(store.results).toHaveLength(1);

    get.mockRejectedValueOnce(axiosStatus(500, 'internal_error'));
    await store.search('Amina again');

    expect(store.results).toEqual([]);
    expect(store.loading).toBe(false);
  });

  /*
   |--------------------------------------------------------------------------
   | Nothing is persisted, and nothing survives a context change
   |--------------------------------------------------------------------------
   */

  it('never writes a result to localStorage or sessionStorage', async () => {
    get.mockResolvedValue({ data: { data: [result()] } });
    const store = useSearchStore();

    await store.search('Amina');

    expect(localStorage.length).toBe(0);
    expect(sessionStorage.length).toBe(0);
    expect(JSON.stringify(localStorage)).not.toContain('Amina');
    expect(JSON.stringify(sessionStorage)).not.toContain('Amina');
  });

  it('clears held results when the branch scope changes', async () => {
    get.mockResolvedValue({ data: { data: [result()] } });
    const auth = useAuthStore();
    auth.branchIds = ['01HZZBRANCH0000000000000001'];

    const store = useSearchStore();
    await store.search('Amina');
    expect(store.results).toHaveLength(1);

    auth.branchIds = ['01HZZBRANCH0000000000000002'];
    await Promise.resolve();

    expect(store.results).toEqual([]);
    expect(store.hasSearched).toBe(false);
  });

  it('clears held results when the active membership changes', async () => {
    get.mockResolvedValue({ data: { data: [result()] } });
    const auth = useAuthStore();
    auth.membership = { id: 'MEMBER1', role: 'front_office', status: 'active' };

    const store = useSearchStore();
    await store.search('Amina');
    expect(store.results).toHaveLength(1);

    auth.membership = { id: 'MEMBER2', role: 'front_office', status: 'active' };
    await Promise.resolve();

    expect(store.results).toEqual([]);
  });

  it('resets completely', async () => {
    get.mockResolvedValue({ data: { data: [result()] } });
    const store = useSearchStore();

    await store.search('Amina');
    store.$reset();

    expect(store.results).toEqual([]);
    expect(store.query).toBe('');
    expect(store.failure).toBe('none');
    expect(store.hasSearched).toBe(false);
  });

  /*
   |--------------------------------------------------------------------------
   | Ordering and contact safety
   |--------------------------------------------------------------------------
   */

  it('ignores a slow earlier response that resolves after a newer query', async () => {
    const store = useSearchStore();

    let resolveFirst: ((value: unknown) => void) | undefined;
    get.mockReturnValueOnce(
      new Promise((resolve) => {
        resolveFirst = resolve;
      }) as never,
    );
    get.mockResolvedValueOnce({ data: { data: [result({ ulid: 'SECOND', title: 'Second' })] } });

    const first = store.search('Amin');
    const second = store.search('Amina');
    await second;

    resolveFirst?.({ data: { data: [result({ ulid: 'FIRST', title: 'First' })] } });
    await first;

    // The newer query wins; a stale response can never overwrite it.
    expect(store.results.map((r) => r.ulid)).toEqual(['SECOND']);
  });

  it('holds no contact value, because the API sends none', async () => {
    get.mockResolvedValue({ data: { data: [result()] } });
    const store = useSearchStore();

    await store.search('Amina');

    const serialized = JSON.stringify(store.results);

    expect(serialized).not.toContain(FULL_PHONE);
    expect(serialized).not.toContain('712345678');
    expect(serialized).not.toContain('phone');
    expect(serialized).not.toContain('email');
  });

  it('exposes no export, download or copy action', () => {
    const store = useSearchStore();

    for (const forbidden of ['export', 'download', 'print', 'copy', 'csv', 'toClipboard']) {
      expect(store).not.toHaveProperty(forbidden);
    }
  });
});

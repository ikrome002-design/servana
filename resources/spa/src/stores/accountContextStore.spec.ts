import { createPinia, setActivePinia } from 'pinia';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { useAccountContextStore } from '@/stores/accountContextStore';
import { apiClient } from '@/services/apiClient';

vi.mock('@/services/apiClient', async () => {
  const actual = await vi.importActual<typeof import('@/services/apiClient')>('@/services/apiClient');

  return {
    ...actual,
    apiClient: { get: vi.fn(), post: vi.fn() },
    primeCsrfCookie: vi.fn().mockResolvedValue(undefined),
  };
});

function context(overrides: Record<string, unknown> = {}) {
  return {
    context_id: 'c'.repeat(32),
    account_key: 'merchant_finance',
    display_name: 'Finance',
    target_host: 'source-host.example',
    default_route: '/dashboard',
    requires_mfa: true,
    merchant_id: 'M1',
    merchant_name: 'Glow Salon',
    branch_id: 'B1',
    branch_name: 'Westlands',
    role_label: 'Finance',
    is_current: false,
    ...overrides,
  };
}

describe('accountContextStore', () => {
  let assign: ReturnType<typeof vi.fn>;

  beforeEach(() => {
    setActivePinia(createPinia());
    vi.clearAllMocks();

    assign = vi.fn();
    Object.defineProperty(window, 'location', {
      configurable: true,
      value: { assign, href: 'http://source-host.example/' },
    });
  });

  afterEach(() => {
    vi.restoreAllMocks();
  });

  it('holds exactly what the server returned', async () => {
    vi.mocked(apiClient.get).mockResolvedValue({ data: { data: [context()] } } as never);

    const store = useAccountContextStore();
    await store.fetchContexts();

    expect(store.contexts).toHaveLength(1);
    expect(store.contexts[0].account_key).toBe('merchant_finance');
    expect(store.loaded).toBe(true);
  });

  it('offers no switcher when there is only one context', async () => {
    vi.mocked(apiClient.get).mockResolvedValue({
      data: { data: [context({ is_current: true })] },
    } as never);

    const store = useAccountContextStore();
    await store.fetchContexts();

    // A control that leads nowhere is a misleading affordance, not a harmless one.
    expect(store.canSwitch).toBe(false);
    expect(store.otherContexts).toHaveLength(0);
  });

  it('offers a switcher when more than one context exists', async () => {
    vi.mocked(apiClient.get).mockResolvedValue({
      data: {
        data: [
          context({ account_key: 'merchant_personnel', is_current: true }),
          context({ context_id: 'd'.repeat(32) }),
        ],
      },
    } as never);

    const store = useAccountContextStore();
    await store.fetchContexts();

    expect(store.canSwitch).toBe(true);
    expect(store.otherContexts).toHaveLength(1);
    expect(store.currentContext?.account_key).toBe('merchant_personnel');
  });

  it('clears state and surfaces an error when the list cannot be loaded', async () => {
    vi.mocked(apiClient.get).mockRejectedValue(new Error('network'));

    const store = useAccountContextStore();
    await store.fetchContexts();

    // A half-populated switcher is worse than none.
    expect(store.contexts).toEqual([]);
    expect(store.error).not.toBeNull();
  });

  it('navigates to the SERVER’s URL and never builds its own', async () => {
    vi.mocked(apiClient.post).mockResolvedValue({
      data: {
        data: {
          target_url: 'https://target-host.example/auth/switch?token=abc',
          target_account_key: 'merchant_finance',
          requires_mfa: true,
        },
      },
    } as never);

    const store = useAccountContextStore();
    await store.switchTo('c'.repeat(32));

    expect(assign).toHaveBeenCalledWith('https://target-host.example/auth/switch?token=abc');

    // The request carries ONLY the opaque id — no role, host, merchant or permission.
    const [, payload] = vi.mocked(apiClient.post).mock.calls[0];
    expect(Object.keys(payload as object)).toEqual(['context_id']);
  });

  it('resets account-scoped stores before leaving the source host', async () => {
    vi.mocked(apiClient.post).mockResolvedValue({
      data: { data: { target_url: 'https://target-host.example/auth/switch?token=abc' } },
    } as never);

    const order: string[] = [];
    assign.mockImplementation(() => order.push('navigate'));

    const store = useAccountContextStore();
    await store.switchTo('c'.repeat(32), { resetStores: () => order.push('reset') });

    expect(order).toEqual(['reset', 'navigate']);
  });

  it('blocks a double submission', async () => {
    vi.mocked(apiClient.post).mockImplementation(
      () =>
        new Promise((resolve) => {
          setTimeout(
            () => resolve({ data: { data: { target_url: 'https://target-host.example/x' } } } as never),
            5,
          );
        }),
    );

    const store = useAccountContextStore();
    const first = store.switchTo('c'.repeat(32));
    // A second mint would supersede the first token, so the user would arrive holding a token the
    // server has already retired.
    await store.switchTo('c'.repeat(32));
    await first;

    expect(apiClient.post).toHaveBeenCalledTimes(1);
  });

  it('recovers so the control is usable again after a failed switch', async () => {
    vi.mocked(apiClient.post).mockRejectedValue(new Error('nope'));

    const store = useAccountContextStore();
    await store.switchTo('c'.repeat(32));

    expect(store.switching).toBe(false);
    expect(store.error).not.toBeNull();
    expect(assign).not.toHaveBeenCalled();
  });

  it('never writes a token to browser storage', async () => {
    const setItem = vi.spyOn(Storage.prototype, 'setItem');

    vi.mocked(apiClient.post).mockResolvedValue({
      data: { data: { target_url: 'https://target-host.example/auth/switch?token=secret' } },
    } as never);

    const store = useAccountContextStore();
    await store.switchTo('c'.repeat(32));

    expect(setItem).not.toHaveBeenCalled();
  });
});

import { afterEach, describe, expect, it, vi } from 'vitest';

import { randomUuid } from '@/utils/uuid';

const V4 = /^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/;

// LOCAL-UAT-001, LUAT-D05: plain-HTTP local hosts are not a secure context, so randomUUID is absent.
describe('randomUuid', () => {
  afterEach(() => {
    vi.unstubAllGlobals();
  });

  it('uses crypto.randomUUID when the context provides it', () => {
    expect(randomUuid()).toMatch(V4);
  });

  it('still returns a unique v4 UUID where randomUUID does not exist (non-secure context)', () => {
    const real = globalThis.crypto;
    vi.stubGlobal('crypto', { getRandomValues: (array: Uint8Array) => real.getRandomValues(array) });

    const a = randomUuid();
    const b = randomUuid();
    expect(a).toMatch(V4);
    expect(b).toMatch(V4);
    expect(a).not.toBe(b);
  });
});

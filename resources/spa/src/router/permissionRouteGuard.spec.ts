import { createPinia, setActivePinia } from 'pinia';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { requiresAnyPermission } from '@/router/guards';
import { useAuthStore } from '@/stores/authStore';

describe('any-permission route guard', () => {
  beforeEach(() => {
    setActivePinia(createPinia());
  });

  it.each(['finance_export.create', 'finance_export.download'])(
    'admits a user holding only %s',
    (permission) => {
      useAuthStore().permissions = [permission];
      const next = vi.fn();

      requiresAnyPermission('finance_export.create', 'finance_export.download')(
        {} as never,
        {} as never,
        next,
      );

      expect(next).toHaveBeenCalledWith();
    },
  );

  it('denies a user holding neither capability', () => {
    useAuthStore().permissions = [];
    const next = vi.fn();

    requiresAnyPermission('finance_export.create', 'finance_export.download')(
      {} as never,
      {} as never,
      next,
    );

    expect(next).toHaveBeenCalledWith({ name: 'home' });
  });
});

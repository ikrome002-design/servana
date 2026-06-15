import { createPinia, setActivePinia } from 'pinia';
import { beforeEach, describe, expect, it, vi } from 'vitest';

const get = vi.fn();
const post = vi.fn();
vi.mock('@/services/apiClient', () => ({
  apiClient: {
    get: (...a: unknown[]) => get(...a),
    post: (...a: unknown[]) => post(...a),
  },
}));

import { useStaffStore } from '@/stores/staffStore';

const invitation = {
  id: 'i1',
  email: 'staff@salon.co.ke',
  role: 'front_office' as const,
  role_title: null,
  branch_id: 'b1',
  status: 'pending' as const,
  resend_count: 0,
  expires_at: '2026-06-18T00:00:00Z',
  last_sent_at: '2026-06-15T00:00:00Z',
};

describe('staffStore', () => {
  beforeEach(() => {
    setActivePinia(createPinia());
    get.mockReset();
    post.mockReset();
  });

  it('invites a staff member and prepends the invitation', async () => {
    post.mockResolvedValueOnce({ data: { data: invitation } });
    const store = useStaffStore();

    await store.invite({ email: 'staff@salon.co.ke', branch_id: 'b1', role: 'front_office' });

    expect(post).toHaveBeenCalledWith('/staff-invitations', {
      email: 'staff@salon.co.ke',
      branch_id: 'b1',
      role: 'front_office',
    });
    expect(store.invitations[0]?.id).toBe('i1');
  });

  it('resends an invitation, updating the row in place', async () => {
    const store = useStaffStore();
    store.invitations = [invitation];
    post.mockResolvedValueOnce({ data: { data: { ...invitation, resend_count: 1 } } });

    await store.resendInvitation('i1');

    expect(post).toHaveBeenCalledWith('/staff-invitations/i1/resend');
    expect(store.invitations[0]?.resend_count).toBe(1);
  });

  it('revokes an invitation', async () => {
    const store = useStaffStore();
    store.invitations = [invitation];
    post.mockResolvedValueOnce({ data: { data: { ...invitation, status: 'revoked' } } });

    await store.revokeInvitation('i1');

    expect(post).toHaveBeenCalledWith('/staff-invitations/i1/revoke');
    expect(store.invitations[0]?.status).toBe('revoked');
  });

  it('accepts an invitation and returns the confirmation message', async () => {
    post.mockResolvedValueOnce({ data: { message: 'Your account is ready.' } });
    const store = useStaffStore();

    const message = await store.acceptInvitation({
      token: 'raw',
      first_name: 'Amina',
      last_name: 'Mwangi',
      phone: '+254700000000',
    });

    expect(post).toHaveBeenCalledWith('/staff-invitations/accept', {
      token: 'raw',
      first_name: 'Amina',
      last_name: 'Mwangi',
      phone: '+254700000000',
    });
    expect(message).toBe('Your account is ready.');
  });

  it('suspends a staff member and updates the roster row', async () => {
    const member = { id: 's1', display_name: 'A B', status: 'active' as const };
    const store = useStaffStore();
    // @ts-expect-error partial roster row is sufficient for this test
    store.staff = [member];
    post.mockResolvedValueOnce({ data: { data: { ...member, status: 'suspended' } } });

    await store.suspendStaff('s1', 'reason');

    expect(post).toHaveBeenCalledWith('/staff/s1/suspend', { reason: 'reason' });
    expect(store.staff[0]?.status).toBe('suspended');
  });
});

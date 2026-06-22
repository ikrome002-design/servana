import { describe, expect, it } from 'vitest';
import { ownsUnauthenticatedResponse, parseApiError } from './apiClient';

describe('parseApiError', () => {
  it('extracts a well-formed error envelope', () => {
    const data = {
      error: {
        code: 'validation_error',
        message: 'The email field is required.',
        fields: { email: ['The email field is required.'] },
        meta: { correlation_id: 'abc123' },
      },
    };
    const result = parseApiError(data);
    expect(result.code).toBe('validation_error');
    expect(result.message).toBe('The email field is required.');
    expect(result.fields).toEqual({ email: ['The email field is required.'] });
    expect(result.meta).toEqual({ correlation_id: 'abc123' });
  });

  it('handles unauthenticated code', () => {
    const result = parseApiError({ error: { code: 'unauthenticated', message: 'Unauthenticated.' } });
    expect(result.code).toBe('unauthenticated');
    expect(result.fields).toEqual({});
    expect(result.meta).toEqual({});
  });

  it('handles permission_denied', () => {
    const result = parseApiError({ error: { code: 'permission_denied', message: 'Forbidden.' } });
    expect(result.code).toBe('permission_denied');
  });

  it('handles merchant_suspended', () => {
    const result = parseApiError({ error: { code: 'merchant_suspended', message: 'Merchant is suspended.' } });
    expect(result.code).toBe('merchant_suspended');
  });

  it('handles period_locked', () => {
    const result = parseApiError({ error: { code: 'period_locked', message: 'Period is locked.' } });
    expect(result.code).toBe('period_locked');
  });

  it('handles duplicate_reference', () => {
    const result = parseApiError({ error: { code: 'duplicate_reference', message: 'Duplicate.' } });
    expect(result.code).toBe('duplicate_reference');
  });

  it('handles rate_limited', () => {
    const result = parseApiError({ error: { code: 'rate_limited', message: 'Too many requests.' } });
    expect(result.code).toBe('rate_limited');
  });

  it('falls back to internal_error for unrecognised envelope', () => {
    const result = parseApiError(null);
    expect(result.code).toBe('internal_error');
    expect(result.message).toBe('An unexpected error occurred.');
  });

  it('falls back when envelope is malformed', () => {
    const result = parseApiError({ unexpected: 'shape' });
    expect(result.code).toBe('internal_error');
  });

  it('handles missing fields and meta gracefully', () => {
    const result = parseApiError({ error: { code: 'internal_error', message: 'Boom.' } });
    expect(result.fields).toEqual({});
    expect(result.meta).toEqual({});
  });
});

describe('ownsUnauthenticatedResponse (401 redirect loop-guard)', () => {
  it('owns the session bootstrap and auth/csrf endpoints (no global redirect)', () => {
    // These 401s are expected/owned by their callers — they must NOT trigger the
    // central revocation redirect, or login/bootstrap would loop.
    expect(ownsUnauthenticatedResponse('/me')).toBe(true);
    expect(ownsUnauthenticatedResponse('/auth/magic-link')).toBe(true);
    expect(ownsUnauthenticatedResponse('/auth/logout')).toBe(true);
    expect(ownsUnauthenticatedResponse('/sanctum/csrf-cookie')).toBe(true);
  });

  it('does not own protected resource calls (mid-session revocation → redirect)', () => {
    expect(ownsUnauthenticatedResponse('/merchant/dashboard')).toBe(false);
    expect(ownsUnauthenticatedResponse('/branches')).toBe(false);
    expect(ownsUnauthenticatedResponse(undefined)).toBe(false);
  });
});

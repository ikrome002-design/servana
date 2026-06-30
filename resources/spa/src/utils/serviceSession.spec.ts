import { describe, expect, it } from 'vitest';
import {
  COMMISSION_PREVIEW_LABEL,
  commissionPreviewSummary,
  serviceSessionStatusLabel,
} from '@/utils/serviceSession';

describe('serviceSession utils', () => {
  it('labels every status with text (never colour-only)', () => {
    expect(serviceSessionStatusLabel('pending')).toBe('Pending');
    expect(serviceSessionStatusLabel('in_progress')).toBe('In progress');
    expect(serviceSessionStatusLabel('completed')).toBe('Completed');
    expect(serviceSessionStatusLabel('cancelled')).toBe('Cancelled');
  });

  it('uses the fixed non-payable preview heading', () => {
    expect(COMMISSION_PREVIEW_LABEL).toBe('Preview — not earned or payable');
  });

  it('never represents "not configured" as a zero amount', () => {
    const summary = commissionPreviewSummary({
      preview_status: 'not_configured',
      reason: 'compensation_not_configured',
      earned: false,
      payable: false,
      amount_minor: null,
      currency: null,
    });
    expect(summary).toBe('Commission is not configured yet.');
    expect(summary).not.toContain('0');
  });

  it('describes salary-only as not applicable', () => {
    expect(
      commissionPreviewSummary({
        preview_status: 'not_applicable',
        reason: 'salary_only',
        earned: false,
        payable: false,
        amount_minor: null,
        currency: null,
      }),
    ).toBe('Commission does not apply (salary only).');
  });

  it('returns empty for a null preview', () => {
    expect(commissionPreviewSummary(null)).toBe('');
  });
});

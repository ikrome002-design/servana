import { describe, expect, it } from 'vitest';
import {
  actionModule,
  ageLabel,
  buildContextBreakdown,
  exportPresentation,
  humanizeAction,
  integrityPresentation,
  isActiveReview,
  isEscalatedSeverity,
  isMaskedValue,
  reviewPresentation,
  severityPresentation,
} from '@/utils/auditPresentation';

describe('auditPresentation (Phase UI-15)', () => {
  it('keeps immutable severity and mutable review status as separate vocabularies', () => {
    expect(severityPresentation('critical').label).toBe('Critical');
    expect(reviewPresentation('under_review').label).toBe('Under review');
    // A review word is not a severity and a severity word is not a review state.
    expect(severityPresentation('open').known).toBe(false);
    expect(reviewPresentation('critical').known).toBe(false);
  });

  it('reports unknown values as unknown — never as a reassuring state', () => {
    expect(severityPresentation('mystery')).toMatchObject({ tone: 'neutral', known: false });
    expect(reviewPresentation(undefined)).toMatchObject({ tone: 'neutral', known: false });
    expect(exportPresentation('zombie')).toMatchObject({ tone: 'neutral', known: false });
    const integrity = integrityPresentation(null);
    expect(integrity.tone).toBe('neutral');
    expect(integrity.label).toBe('Not checked');
  });

  it('never presents a partial chain check as verified', () => {
    expect(integrityPresentation('partial').label).not.toMatch(/^verified$/i);
    expect(integrityPresentation('partial').tone).not.toBe('success');
    expect(integrityPresentation('broken').tone).toBe('error');
    expect(integrityPresentation('verified').tone).toBe('success');
  });

  it('ranks high and critical as the escalated band and open/under-review/reopened as active', () => {
    expect(isEscalatedSeverity('critical')).toBe(true);
    expect(isEscalatedSeverity('high')).toBe(true);
    expect(isEscalatedSeverity('warning')).toBe(false);
    expect(['open', 'under_review', 'reopened'].every(isActiveReview)).toBe(true);
    expect(['resolved', 'dismissed', null].some(isActiveReview)).toBe(false);
  });

  it('humanises action tokens and classifies their module without hiding the token', () => {
    expect(humanizeAction('customer_payment.duplicate_override_approved')).toBe('Customer payment — duplicate override approved');
    expect(humanizeAction('unauthorized_access')).toBe('Unauthorized access');
    expect(actionModule('cash_up.approved')).toBe('Finance');
    expect(actionModule('payout_run.marked_paid')).toBe('Compensation');
    expect(actionModule('personnel.sms.export_attempt_blocked')).toBe('Contact protection');
    expect(actionModule('queue_entry.transferred')).toBe('Operations');
  });

  it('builds a structured before/after diff from the recorder conventions and keeps the rest as facts', () => {
    const { changes, facts } = buildContextBreakdown({
      old_values: { name: 'Westlands', phone: '***123' },
      new_values: { name: 'Westlands Studio', phone: '***123' },
      previous_state: 'draft',
      new_state: 'approved',
      reason: 'Owner request',
    });

    expect(changes).toEqual([
      { field: 'Name', before: 'Westlands', after: 'Westlands Studio', changed: true },
      { field: 'Phone', before: '***123', after: '***123', changed: false },
      { field: 'State', before: 'draft', after: 'approved', changed: true },
    ]);
    expect(facts).toEqual([{ key: 'reason', label: 'Reason', value: 'Owner request' }]);
  });

  it('recognises server masking markers', () => {
    expect(isMaskedValue('[redacted]')).toBe(true);
    expect(isMaskedValue('[restricted]')).toBe(true);
    expect(isMaskedValue('j***@e***.com')).toBe(true);
    expect(isMaskedValue('Westlands')).toBe(false);
  });

  it('formats review age from server time without inventing a value', () => {
    const now = new Date('2026-10-11T12:00:00Z');
    expect(ageLabel('2026-10-11T11:30:00Z', now)).toBe('30 min');
    expect(ageLabel('2026-10-10T12:00:00Z', now)).toBe('24 h');
    expect(ageLabel('2026-10-06T12:00:00Z', now)).toBe('5 days');
    expect(ageLabel(null, now)).toBe('Age unknown');
  });
});

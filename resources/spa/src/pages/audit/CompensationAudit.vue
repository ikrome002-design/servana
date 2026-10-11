<script setup lang="ts">
import AuditViewPage from '@/components/audit/AuditViewPage.vue';

/**
 * Compensation Audit — Appendix A §12.4.8 (Phase 19 read; Phase UI-15 composition). The
 * compensation read segment (`audit.compensation.view`): plan and commission-rule governance,
 * salary and commission ledger facts, payout runs and earnings queries. Read-only; salary values
 * may be restricted by the server's audit masking policy.
 */
const presets = [
  { label: 'Critical — backdated, high-value, paid', severity: 'critical' },
  { label: 'High', severity: 'high' },
  { label: 'Flagged for review', flagged: 'flagged' },
];
</script>

<template>
  <AuditViewPage
    id-prefix="audit-compensation"
    eyebrow="Audit views"
    title="Compensation Audit"
    description="Compensation plans, commission rules, salary and commission ledgers, payout runs and earnings queries — with approvers and effective dates as recorded."
    domain="compensation"
    tone="green"
    :presets="presets"
    :includes="[
      'Compensation plans and commission rules: drafts, submissions, approvals, backdated approvals, supersession',
      'Salary accruals, reversals and adjustments',
      'Commission earned and reversed from validated payments and refunds',
      'Payout runs: prepared, submitted, verified, approved (including high-value), rejected, marked paid',
      'Earnings statements and earnings queries',
    ]"
    masking="Salary and gross or net pay values are restricted by the server's audit policy. Amounts are never totalled across currencies on this page."
    :boundaries="[
      'Configure plans, rules or salaries',
      'Prepare, approve or mark a payout run paid',
      'Answer or close an earnings query',
    ]"
    empty-message="No compensation events match these filters in your branch."
  />
</template>

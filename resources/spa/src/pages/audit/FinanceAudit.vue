<script setup lang="ts">
import AuditViewPage from '@/components/audit/AuditViewPage.vue';

/**
 * Finance Audit — Appendix A §12.4.7 (Phase 19 read; Phase UI-15 composition). The finance read
 * segment (`audit.finance.view`): invoices, merchant-client payments, receipts, refunds, disputes,
 * cash-up and period locks. Read-only — this is NOT a Finance operational screen. Subscription
 * payment attempts are not shown: their Wallet runtime sits behind External Gate W (20D-W).
 */
const presets = [
  { label: 'Critical', severity: 'critical' },
  { label: 'High', severity: 'high' },
  { label: 'Warnings', severity: 'warning' },
  { label: 'Flagged for review', flagged: 'flagged' },
];
</script>

<template>
  <AuditViewPage
    id-prefix="audit-finance"
    eyebrow="Audit views"
    title="Finance Audit"
    description="Invoices, payment recording and validation, receipts, refunds, disputes, cash-up and period locks — with maker, checker and period details wherever the event recorded them."
    domain="finance"
    tone="warm"
    :presets="presets"
    :includes="[
      'Invoices created, finalised, voided or adjusted',
      'Payment recording, duplicate-reference checks and overrides, validation, rejection and correction',
      'Receipts issued, reissued and downloaded',
      'Refunds and finance disputes',
      'Cash-up submit, review, approve, reject and correction',
      'Financial period lock, reopen request, approval and reopen',
      'Finance exports and platform-fee events for the branch',
    ]"
    masking="Payment references and M-Pesa numbers are masked to their last characters; amounts are recorded in minor units with their currency. Subscription payment attempts are not shown until External Gate W opens."
    :boundaries="[
      'Validate, reject or correct a payment',
      'Issue or reissue a receipt; approve a refund or dispute',
      'Approve cash-up; lock or reopen a period',
      'Initiate a subscription payment',
    ]"
    empty-message="No finance events match these filters in your branch."
  />
</template>

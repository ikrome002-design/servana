<script setup lang="ts">
import AuditViewPage from '@/components/audit/AuditViewPage.vue';

/**
 * Operational Audit — Appendix A §12.4.6 (Phase UI-15). The `operational` server lens over the
 * branch's general audit segment (`audit.branch_events.view`). Read-only: no queue, appointment,
 * session, service or branch-day control exists on this page.
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
    id-prefix="audit-operations"
    eyebrow="Audit views"
    title="Operational Audit"
    description="Clients, appointments, walk-ins, queues, service sessions, services and branch-day operations — as recorded, in order."
    view="operational"
    tone="teal"
    :presets="presets"
    :includes="[
      'Client records created and updated',
      'Appointments created, assigned, transferred, rescheduled, checked in, cancelled or missed',
      'Walk-ins and queue entries created, called, reordered, transferred, cancelled or missed',
      'Service sessions started, completed or cancelled',
      'Service catalogue, eligibility and availability changes',
      'Branch day open, close and reopen; hours and calendar exceptions',
    ]"
    masking="Client contact details and references are masked by the server before they reach this page. States, positions, reasons and timings appear as they were recorded."
    :boundaries="[
      'Create, move or cancel appointments, queue entries or sessions',
      'Change services, prices, eligibility or availability',
      'Open, pause or close the branch day',
    ]"
    empty-message="No operational events match these filters in your branch."
  />
</template>

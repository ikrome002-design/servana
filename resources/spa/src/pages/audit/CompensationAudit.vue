<script setup lang="ts">
import AuditDomainEvents from '@/components/audit/AuditDomainEvents.vue';

/**
 * Compensation-domain Audit view (Plan §19.3, §70; Phase 19). The canonical
 * `audit.compensation.view` permission is active, but compensation business
 * domains are owned by Phases 20F–20H. This is the REAL Audit read surface; until
 * compensation events exist it shows an honest empty state — no compensation
 * events, routes, or data are fabricated here.
 */
</script>

<template>
  <AuditDomainEvents
    domain="compensation"
    title="Compensation audit"
    subtitle="Read-only, branch-scoped compensation-domain audit events. Values are masked."
    empty-message="No compensation audit events yet. Compensation activity is recorded once its owning phases (20F–20H) go live."
    detail-route-name="audit.event-detail"
  />
</template>

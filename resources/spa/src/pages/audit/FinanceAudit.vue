<script setup lang="ts">
import AuditDomainEvents from '@/components/audit/AuditDomainEvents.vue';

/**
 * Finance-domain Audit view (Plan §19.3, §70; Phase 19). For the Audit role via
 * `audit.finance.view`; the server also serves Finance via `finance.audit.view`
 * (MFA-enforced). Read-only, branch-scoped, masked — this is NOT a Finance
 * operational screen; no finance record can be mutated here.
 */
</script>

<template>
  <AuditDomainEvents
    domain="finance"
    title="Finance audit"
    subtitle="Read-only, branch-scoped finance-domain audit events. Values are masked."
    empty-message="No finance audit events match this filter."
    detail-route-name="audit.event-detail"
  />
</template>

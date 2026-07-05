<script setup lang="ts">
import AuditDomainEvents from '@/components/audit/AuditDomainEvents.vue';

/**
 * Finance-role Finance Audit view (Plan §19.3, §70; Phase 19). The Finance role's
 * OWN, MFA-gated, branch-scoped, masked read of the finance-domain audit trail via
 * `finance.audit.view` — distinct from the Audit role's `audit.finance.view` (both
 * hit the same `GET /audit-logs/finance` endpoint, which authorises either key; the
 * backend `EnsurePrivilegedMfa` gate enforces Finance MFA authoritatively). This is
 * NOT a Finance operational screen: read-only, no mutation controls, no flagged-event
 * review controls. Reuses the shared AuditDomainEvents panel (no duplicate transport
 * contract); list-only (per-event drill-down is the Audit role's surface).
 */
</script>

<template>
  <AuditDomainEvents
    domain="finance"
    title="Finance audit"
    subtitle="Read-only, branch-scoped finance-domain audit events. Values are masked."
    empty-message="No finance audit events match this filter."
    :mfa-note="true"
  />
</template>

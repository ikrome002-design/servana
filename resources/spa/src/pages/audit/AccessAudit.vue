<script setup lang="ts">
import AuditViewPage from '@/components/audit/AuditViewPage.vue';

/**
 * Access and Security Audit — Appendix A §12.4.9 (Phase UI-15). The `access` server lens over the
 * branch's general segment: invitations, membership and branch-assignment changes, permission
 * overrides and blocked self-escalation, unauthorized attempts, file and export access. Identity-
 * level sign-in events carry no branch and are therefore outside a branch auditor's scope.
 */
const presets = [
  { label: 'High', severity: 'high' },
  { label: 'Critical', severity: 'critical' },
  { label: 'Warnings', severity: 'warning' },
  { label: 'Flagged for review', flagged: 'flagged' },
];
</script>

<template>
  <AuditViewPage
    id-prefix="audit-access"
    eyebrow="Audit views"
    title="Access and Security Audit"
    description="Who was invited, granted, changed, revoked or refused — and every unauthorized attempt against your branch, each kept as its own event."
    view="access"
    tone="teal"
    :presets="presets"
    group-repeats
    :includes="[
      'Staff invitations created, resent, revoked and accepted',
      'Membership activation, suspension and deactivation',
      'Branch assignment granted or revoked',
      'Permission overrides, blocked self-escalation and denied writes',
      'Unauthorized access attempts and denied file access',
      'File and audit-export downloads and revocations',
    ]"
    masking="Email addresses are masked; network and device identifiers are not returned to the Audit account. Platform-only security detail stays with Citrus Labs."
    :boundaries="[
      'Change a role, permission or branch assignment',
      'Revoke another user\'s sessions or invitations',
      'See platform-only security detail',
    ]"
    empty-message="No access or security events match these filters in your branch."
  />
</template>

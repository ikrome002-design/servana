<script setup lang="ts">
import AuditViewPage from '@/components/audit/AuditViewPage.vue';

/**
 * Preferred Personnel and Contact Protection Audit — Appendix A §12.4.10 (Phase UI-15). The
 * `privacy_preferred` server lens: client contact and consent changes, Personnel served-client
 * access, transfers where a preferred-personnel request can be overridden, Personnel SMS
 * selection/confirmation/outcome/charges, and contact-export-shaped denials. No contact list,
 * phone number or message body is returned to this page — by the server, not by hiding.
 */
const presets = [
  { label: 'High', severity: 'high' },
  { label: 'Critical', severity: 'critical' },
  { label: 'Flagged for review', flagged: 'flagged' },
];
</script>

<template>
  <AuditViewPage
    id-prefix="audit-privacy"
    eyebrow="Audit views"
    title="Preferred Personnel and Contact Protection Audit"
    description="Client contact and consent changes, served-client access, preferred-personnel transfers, Personnel SMS and blocked contact-export attempts."
    view="privacy_preferred"
    tone="warm"
    :presets="presets"
    group-repeats
    :includes="[
      'Client records and SMS consent opted in or out',
      'Personnel opening their own served-client context',
      'Appointment and queue transfers, where a preferred-personnel request can be overridden',
      'Personnel SMS previews, campaigns, confirmations, suppressions, deliveries and charges',
      'Contact-export-shaped requests that were blocked',
    ]"
    masking="Personnel SMS events carry counts, outcome classes and charges only — never a phone number, recipient identity or message body. There is no contact export anywhere in Servana."
    :boundaries="[
      'View or export client contact lists',
      'Send or cancel an SMS',
      'Change a preferred-personnel assignment',
      'Unmask a protected value',
    ]"
    empty-message="No contact-protection or preferred-personnel events match these filters in your branch."
  />
</template>

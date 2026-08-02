<script setup lang="ts">
/**
 * SvAuditEvent — one row of an audit trail (Phase UI-04; Plan §19, §10.2).
 *
 * READ-ONLY, and structurally so: it emits nothing and offers no control that changes anything.
 * The Audit account is read-only by authority boundary (CLAUDE.md guardrail 8), and a shared
 * component that could mutate an audit record would be a way around that.
 *
 * It renders only what the server already chose to disclose. `audit_logs` values are masked
 * server-side by `AuditValueMasker` before they ever reach the API, so this component performs no
 * masking of its own — reimplementing it here would create a second, weaker masking authority
 * that could disagree with the first.
 *
 * The metadata disclosure is a native `<details>`: keyboard operable and state-exposed for free.
 */
import SvDateTime from '@/components/ui/SvDateTime.vue';
import SvStatusBadge, { type SvStatusTone } from '@/components/ui/SvStatusBadge.vue';
import { SvIconChevronDown } from '@/design-system/icons';

withDefaults(
  defineProps<{
    /** Action key, e.g. `payout_run.approved`. Already authorized for this viewer. */
    action: string;
    /** Actor display name as the API supplied it. Never an internal id. */
    actor?: string | null;
    at: string | null;
    /** Context line, e.g. the branch or record label the server chose to disclose. */
    context?: string | null;
    statusLabel?: string;
    statusTone?: SvStatusTone;
    /** Already-masked metadata. Rendered as text; no masking happens here. */
    metadata?: Record<string, string> | null;
  }>(),
  {
    actor: null,
    context: null,
    statusLabel: undefined,
    statusTone: 'neutral',
    metadata: null,
  },
);
</script>

<template>
  <article
    class="rounded-card border border-sv-border bg-sv-surface-raised p-4"
    data-testid="sv-audit-event"
  >
    <div class="flex flex-wrap items-center gap-2">
      <p class="font-medium text-sv-text">
        {{ action }}
      </p>
      <SvStatusBadge
        v-if="statusLabel"
        :label="statusLabel"
        :tone="statusTone"
        size="sm"
      />
    </div>

    <dl class="mt-2 grid grid-cols-[minmax(0,auto)_minmax(0,1fr)] gap-x-3 gap-y-1 text-xs">
      <dt class="font-medium text-sv-text-muted">
        When
      </dt>
      <dd class="text-sv-text">
        <SvDateTime :value="at" />
      </dd>

      <template v-if="actor !== null">
        <dt class="font-medium text-sv-text-muted">
          Who
        </dt>
        <dd class="text-sv-text">
          {{ actor }}
        </dd>
      </template>

      <template v-if="context !== null">
        <dt class="font-medium text-sv-text-muted">
          Context
        </dt>
        <dd class="text-sv-text">
          {{ context }}
        </dd>
      </template>
    </dl>

    <details
      v-if="metadata !== null && Object.keys(metadata).length > 0"
      class="group mt-3"
    >
      <summary
        class="sv-focus-ring flex min-h-sv-touch cursor-pointer list-none items-center gap-1 rounded-control text-xs font-medium text-sv-link"
      >
        Details
        <SvIconChevronDown
          aria-hidden="true"
          class="h-4 w-4 shrink-0 transition-transform duration-sv-fast group-open:rotate-180 motion-reduce:transition-none"
        />
      </summary>
      <dl class="mt-2 grid grid-cols-[minmax(0,auto)_minmax(0,1fr)] gap-x-3 gap-y-1 text-xs">
        <template
          v-for="(value, key) in metadata"
          :key="key"
        >
          <dt class="font-medium text-sv-text-muted">
            {{ key }}
          </dt>
          <!-- Text only. The server masked this; nothing is re-masked or re-interpreted here. -->
          <dd class="min-w-0 break-words text-sv-text">
            {{ value }}
          </dd>
        </template>
      </dl>
    </details>
  </article>
</template>

<script setup lang="ts">
import { computed, onMounted } from 'vue';
import { useRoute, RouterLink } from 'vue-router';
import SvCard from '@/components/ui/SvCard.vue';
import SvStateBoundary from '@/components/ui/SvStateBoundary.vue';
import { useAuditEventStore } from '@/stores/auditEventStore';

/**
 * Immutable audit event detail (Plan §70, §74; Phase 19). Strictly read-only —
 * there are NO source-record mutation controls. Values are masked server-side; this
 * screen never renders internal ids, hashes, storage paths, signed URLs, tokens, or
 * unmasked PII. The masked `context` map is displayed as safe key/value pairs.
 */
const route = useRoute();
const store = useAuditEventStore();

const boundaryState = computed<'loading' | 'empty' | 'error' | 'success'>(() => {
  if (store.loading) return 'loading';
  if (store.error) return 'error';
  if (!store.current) return 'empty';
  return 'success';
});

const contextEntries = computed<Array<[string, string]>>(() => {
  const ctx = store.current?.context ?? {};
  return Object.entries(ctx).map(([key, value]) => [key, typeof value === 'string' ? value : JSON.stringify(value)]);
});

onMounted(() => {
  void store.fetchEvent(String(route.params.id));
});
</script>

<template>
  <section class="p-4 md:p-6">
    <RouterLink
      :to="{ name: 'audit.branch-events' }"
      class="inline-flex min-h-[44px] items-center text-sm font-medium text-heading underline hover:no-underline focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary"
    >
      ← Back to audit log
    </RouterLink>

    <SvStateBoundary
      class="mt-4"
      :state="boundaryState"
      error-message="We couldn’t load this audit event."
      empty-message="This audit event was not found."
      @retry="() => store.fetchEvent(String(route.params.id))"
    >
      <div
        v-if="store.current"
        class="flex flex-col gap-4"
      >
        <header>
          <h1
            class="font-display text-2xl font-bold text-heading"
            data-testid="audit-detail-action"
          >
            {{ store.current.action }}
          </h1>
          <p class="mt-1 text-sm text-text-muted">
            Read-only audit record. Sensitive values are masked.
          </p>
        </header>

        <SvCard
          as="section"
          padding="md"
        >
          <dl class="grid grid-cols-1 gap-x-6 gap-y-2 sm:grid-cols-2">
            <div>
              <dt class="text-xs font-medium uppercase text-text-muted">
                Severity
              </dt>
              <dd class="text-sm text-text">
                {{ store.current.severity }}
              </dd>
            </div>
            <div>
              <dt class="text-xs font-medium uppercase text-text-muted">
                Actor
              </dt>
              <dd class="text-sm text-text">
                {{ store.current.actor ?? '—' }}
              </dd>
            </div>
            <div>
              <dt class="text-xs font-medium uppercase text-text-muted">
                Subject
              </dt>
              <dd class="text-sm text-text">
                {{ store.current.subject_type ?? '—' }}
              </dd>
            </div>
            <div>
              <dt class="text-xs font-medium uppercase text-text-muted">
                Occurred
              </dt>
              <dd class="text-sm text-text">
                {{ store.current.created_at ?? '—' }}
              </dd>
            </div>
          </dl>
        </SvCard>

        <SvCard
          as="section"
          padding="md"
        >
          <h2 class="font-display text-lg font-semibold text-heading">
            Context (masked)
          </h2>
          <p
            v-if="contextEntries.length === 0"
            class="mt-2 text-sm text-text-muted"
          >
            No additional context.
          </p>
          <dl
            v-else
            class="mt-2 flex flex-col gap-2"
            data-testid="audit-detail-context"
          >
            <div
              v-for="[key, value] in contextEntries"
              :key="key"
              class="flex flex-col border-b border-border pb-2 sm:flex-row sm:gap-4"
            >
              <dt class="min-w-40 text-xs font-medium uppercase text-text-muted">
                {{ key }}
              </dt>
              <dd class="break-words text-sm text-text">
                {{ value }}
              </dd>
            </div>
          </dl>
        </SvCard>
      </div>
    </SvStateBoundary>
  </section>
</template>

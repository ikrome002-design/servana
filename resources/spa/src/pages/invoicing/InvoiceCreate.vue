<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { useRouter } from 'vue-router';
import SvButton from '@/components/ui/SvButton.vue';
import SvCard from '@/components/ui/SvCard.vue';
import SvStateBoundary from '@/components/ui/SvStateBoundary.vue';
import { useInvoiceStore } from '@/stores/invoiceStore';
import { useServiceSessionStore } from '@/stores/serviceSessionStore';
import type { ServiceSession } from '@/types/models';

// Front Office invoice creation (Plan §40; Phase 17). Select one or more COMPLETED
// service sessions that share a client; the backend derives every price, fee, and
// total under lock (the browser never supplies authoritative money). Sessions for a
// different client are disabled so only compatible sources are grouped. NO payment or
// receipt control appears here.
const sessionStore = useServiceSessionStore();
const invoiceStore = useInvoiceStore();
const router = useRouter();

const selected = ref<Set<string>>(new Set());
const selectedClientId = ref<string | null>(null);
const saving = ref(false);
const actionError = ref<string | null>(null);

const completed = computed<ServiceSession[]>(() => sessionStore.sessions.filter((s) => s.status === 'completed'));

const boundaryState = computed<'loading' | 'empty' | 'error' | 'success'>(() => {
  if (sessionStore.loading) return 'loading';
  if (sessionStore.error) return 'error';
  if (completed.value.length === 0) return 'empty';
  return 'success';
});

function isSelectable(session: ServiceSession): boolean {
  return selectedClientId.value === null || selectedClientId.value === (session.client?.id ?? null);
}

function toggle(session: ServiceSession): void {
  if (!isSelectable(session)) return;
  const id = session.id;
  if (selected.value.has(id)) {
    selected.value.delete(id);
    if (selected.value.size === 0) selectedClientId.value = null;
  } else {
    selected.value.add(id);
    selectedClientId.value = session.client?.id ?? null;
  }
  selected.value = new Set(selected.value);
}

async function createDraft(): Promise<void> {
  if (selected.value.size === 0 || selectedClientId.value === null) return;
  saving.value = true;
  actionError.value = null;
  try {
    const invoice = await invoiceStore.createDraft(selectedClientId.value, [...selected.value]);
    void router.push({ name: 'front-office.invoices.detail', params: { id: invoice.id } });
  } catch {
    actionError.value = 'Unable to create the invoice. A selected service may already be invoiced.';
  } finally {
    saving.value = false;
  }
}

onMounted(() => {
  sessionStore.filterStatus = 'completed';
  void sessionStore.fetchSessions();
});
</script>

<template>
  <section class="p-4 md:p-6">
    <h1 class="font-display text-2xl font-bold text-heading">
      New invoice
    </h1>
    <p class="mt-1 text-sm text-text-muted">
      Select one or more completed services for the same client.
    </p>

    <SvStateBoundary
      class="mt-6"
      :state="boundaryState"
      empty-message="There are no completed services to invoice."
      error-message="We couldn’t load completed services."
      @retry="() => sessionStore.fetchSessions()"
    >
      <ul
        class="flex flex-col gap-3"
        aria-label="Completed services"
      >
        <li
          v-for="session in completed"
          :key="session.id"
        >
          <SvCard
            as="label"
            padding="md"
            :class="[
              'block cursor-pointer',
              !isSelectable(session) ? 'opacity-50' : '',
              selected.has(session.id) ? 'ring-2 ring-primary' : '',
            ]"
          >
            <div class="flex items-start gap-3">
              <input
                type="checkbox"
                class="mt-1 h-5 w-5"
                :checked="selected.has(session.id)"
                :disabled="!isSelectable(session)"
                :aria-label="`Select ${session.service?.name ?? 'service'} for ${session.client?.full_name ?? 'client'}`"
                @change="toggle(session)"
              >
              <div class="flex-1">
                <p class="font-display text-base font-semibold text-heading">
                  {{ session.client?.full_name ?? 'Client' }}
                </p>
                <p class="mt-0.5 text-sm text-text-muted">
                  {{ session.service?.name }}
                  <span v-if="session.personnel"> · {{ session.personnel.display_name }}</span>
                </p>
              </div>
            </div>
          </SvCard>
        </li>
      </ul>
    </SvStateBoundary>

    <p
      v-if="actionError"
      class="mt-4 text-sm text-danger"
      role="alert"
    >
      {{ actionError }}
    </p>

    <div class="mt-6 flex items-center justify-between gap-3">
      <p
        class="text-sm text-text-muted"
        data-testid="selected-count"
      >
        {{ selected.size }} selected
      </p>
      <SvButton
        data-testid="create-draft"
        :loading="saving"
        :disabled="selected.size === 0"
        @click="createDraft"
      >
        Create draft
      </SvButton>
    </div>
  </section>
</template>

<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import PermissionGate from '@/components/auth/PermissionGate.vue';
import SvButton from '@/components/ui/SvButton.vue';
import SvCard from '@/components/ui/SvCard.vue';
import SvInput from '@/components/ui/SvInput.vue';
import SvStateBoundary from '@/components/ui/SvStateBoundary.vue';
import { useClientStore } from '@/stores/clientStore';

// Front Office client directory + search (Plan §35; Phase 15A). Contact is ALWAYS
// masked by the server. Search matches name or phone (branch/tenant-scoped). The
// API is the boundary; the create button is a UX-only permission gate.
const clients = useClientStore();
const query = ref('');

const boundaryState = computed<'loading' | 'empty' | 'error' | 'success'>(() => {
  if (clients.loading) return 'loading';
  if (clients.error) return 'error';
  if (clients.clients.length === 0) return 'empty';
  return 'success';
});

const emptyMessage = computed(() =>
  clients.lastQuery === '' ? 'No clients yet. Add your first client.' : 'No clients match your search.',
);

onMounted(() => {
  void clients.fetchClients();
});

function search(): void {
  void clients.fetchClients(query.value);
}
</script>

<template>
  <section class="p-4 md:p-6">
    <div class="flex flex-wrap items-center justify-between gap-3">
      <h1 class="font-display text-2xl font-bold text-heading">
        Clients
      </h1>
      <PermissionGate permission="client.create">
        <RouterLink :to="{ name: 'front-office.clients.create' }">
          <SvButton
            variant="primary"
            data-testid="add-client"
          >
            Add client
          </SvButton>
        </RouterLink>
      </PermissionGate>
    </div>

    <form
      class="mt-4 flex items-end gap-2"
      novalidate
      role="search"
      @submit.prevent="search"
    >
      <div class="grow">
        <SvInput
          id="client-search"
          v-model="query"
          label="Search clients"
          hint="By name or phone number."
          placeholder="e.g. Amina or 0712…"
        />
      </div>
      <SvButton
        type="submit"
        variant="secondary"
        data-testid="search-clients"
      >
        Search
      </SvButton>
    </form>

    <SvStateBoundary
      class="mt-6"
      :state="boundaryState"
      :empty-message="emptyMessage"
      error-message="We couldn’t load clients."
      @retry="() => clients.fetchClients(query)"
    >
      <ul
        class="flex flex-col gap-3"
        aria-label="Clients"
      >
        <li
          v-for="client in clients.clients"
          :key="client.id"
        >
          <SvCard
            as="article"
            padding="md"
          >
            <div class="flex items-center justify-between gap-2">
              <div>
                <h2 class="font-display text-base font-semibold text-heading">
                  {{ client.full_name }}
                </h2>
                <p class="mt-0.5 text-sm text-text-muted">
                  {{ client.phone_masked }}
                  <span v-if="client.has_email"> · {{ client.email_masked }}</span>
                </p>
              </div>
              <RouterLink
                :to="{ name: 'front-office.clients.detail', params: { id: client.id } }"
                class="text-sm font-semibold text-heading underline"
              >
                View
              </RouterLink>
            </div>
          </SvCard>
        </li>
      </ul>
    </SvStateBoundary>
  </section>
</template>

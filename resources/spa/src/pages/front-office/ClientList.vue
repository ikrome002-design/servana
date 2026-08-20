<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import PermissionGate from '@/components/auth/PermissionGate.vue';
import SvButton from '@/components/ui/SvButton.vue';
import SvCard from '@/components/ui/SvCard.vue';
import SvOperationalHero from '@/components/ui/SvOperationalHero.vue';
import SvStateBoundary from '@/components/ui/SvStateBoundary.vue';
import SvTextInput from '@/components/ui/SvTextInput.vue';
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
  <section class="mx-auto max-w-6xl">
    <SvOperationalHero
      eyebrow="Client welcome desk"
      title="Clients"
      description="Find a branch client quickly without exposing full contact details, or create the next client record with server-side duplicate protection."
    >
      <template #actions>
        <PermissionGate permission="client.create">
          <RouterLink
            class="sv-focus-ring inline-flex min-h-sv-touch items-center rounded-control bg-primary px-4 py-2 text-sm font-bold text-brand-deep"
            :to="{ name: 'front-office.clients-create' }"
            data-testid="add-client"
          >
            Add client
          </RouterLink>
        </PermissionGate>
      </template>
    </SvOperationalHero>

    <SvCard
      class="mt-5"
      padding="lg"
    >
      <form
        class="grid items-end gap-3 md:grid-cols-[1fr_auto]"
        novalidate
        role="search"
        @submit.prevent="search"
      >
        <SvTextInput
          id="client-search"
          v-model="query"
          label="Search clients"
          help="By name or phone number. Contact remains masked."
          placeholder="e.g. Amina or 0712…"
        />
        <SvButton
          type="submit"
          variant="secondary"
          data-testid="search-clients"
        >
          Search
        </SvButton>
      </form>

      <div class="mt-6">
        <SvStateBoundary
          :state="boundaryState"
          :empty-message="emptyMessage"
          error-message="We couldn’t load clients."
          @retry="() => clients.fetchClients(query)"
        >
          <ul
            class="grid gap-3 md:grid-cols-2"
            aria-label="Clients"
          >
            <li
              v-for="client in clients.clients"
              :key="client.id"
            >
              <SvCard
                as="article"
                padding="md"
                class="h-full border-l-4 border-l-sv-brand-secondary"
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
                    :to="{ name: 'front-office.client-detail', params: { clientUlid: client.id } }"
                    class="sv-focus-ring inline-flex min-h-sv-touch items-center rounded-control px-3 text-sm font-semibold text-heading underline"
                  >
                    View
                  </RouterLink>
                </div>
              </SvCard>
            </li>
          </ul>
        </SvStateBoundary>
      </div>
    </SvCard>
  </section>
</template>

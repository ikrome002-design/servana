<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import SvMaskedIdentity from '@/components/ui/SvMaskedIdentity.vue';
import SvPagination from '@/components/ui/SvPagination.vue';
import SvStatTile from '@/components/ui/SvStatTile.vue';
import SvStateBoundary from '@/components/ui/SvStateBoundary.vue';
import SvStatusBadge from '@/components/ui/SvStatusBadge.vue';
import SvTextInput from '@/components/ui/SvTextInput.vue';
import SvTonalPageHeader from '@/components/ui/SvTonalPageHeader.vue';
import { SvIconLocked, SvIconSearch } from '@/design-system/icons';
import { usePersonnelExperienceStore } from '@/stores/personnelExperienceStore';
import { humanizeStatus, nairobiDate } from '@/utils/personnelStatus';

/*
 * Served clients — the highest privacy bar in the Personnel account (UI-14).
 *
 * The server returns only clients this Personnel member personally completed a session with, and
 * only a masked phone. This page has, and must never gain: an export, download, print, copy-all,
 * clipboard, `tel:`/`sms:` link or raw-number field. Follow-up happens inside Servana's SMS
 * composer, which re-checks consent and own-scope on the server at preview and confirm time.
 */
const store = usePersonnelExperienceStore();
const search = ref('');
const page = ref(1);
const eligibility = ref<'all' | 'eligible' | 'ineligible'>('all');
const state = computed(() => store.loading.clients ? 'loading' : store.errors.clients ? 'error' : store.clients.length ? 'success' : 'empty');
const meta = computed(() => store.meta.clients);
/** View filter over records already returned for this own-scope page; it never widens the request. */
const visible = computed(() => store.clients.filter((client) => eligibility.value === 'all' || (eligibility.value === 'eligible') === client.sms_eligible));
const eligibleCount = computed(() => store.clients.filter((client) => client.sms_eligible).length);
const filters = [
  { value: 'all', label: 'All' },
  { value: 'eligible', label: 'SMS eligible' },
  { value: 'ineligible', label: 'Not eligible' },
] as const;
function load(): void {
  const params: Record<string, string | number> = { page: page.value };
  if (search.value.trim()) params.search = search.value.trim();
  void store.fetchClients(params);
}
function runSearch(): void { page.value = 1; load(); }
function goTo(next: number): void { page.value = next; load(); }
onMounted(load);
</script>

<template>
  <section
    class="mx-auto max-w-7xl"
    data-testid="personnel-served-clients"
  >
    <SvTonalPageHeader
      title="Served clients"
      eyebrow="My clients"
      tone="teal"
      description="Only clients you personally served. Contact stays masked, there is no export, and follow-up happens inside Servana."
    >
      <template #actions>
        <RouterLink
          :to="{ name: 'personnel.messages-compose' }"
          class="sv-focus-ring inline-flex min-h-sv-touch items-center justify-center rounded-control bg-sv-brand px-4 text-sm font-bold text-sv-text-on-brand hover:bg-sv-brand-hover"
        >
          Compose in Servana
        </RouterLink>
      </template>
      <div class="grid grid-cols-2 gap-3 md:grid-cols-3">
        <SvStatTile
          label="Personally served"
          tone="teal"
        >
          {{ meta?.total ?? store.clients.length }}
        </SvStatTile>
        <SvStatTile
          label="SMS eligible here"
          tone="green"
        >
          {{ eligibleCount }}
        </SvStatTile>
        <div class="col-span-2 flex items-start gap-3 rounded-card border border-sv-info-border bg-sv-info-bg p-4 text-sm text-sv-info-fg md:col-span-1">
          <SvIconLocked
            aria-hidden="true"
            class="mt-0.5 h-5 w-5 shrink-0"
          />
          <p><strong>Privacy boundary:</strong> client contact export is prohibited. These profiles expose relationship context, not a contact list.</p>
        </div>
      </div>
    </SvTonalPageHeader>

    <div class="mb-5 flex flex-col gap-3 rounded-card border border-sv-border bg-sv-surface-raised p-4 shadow-card md:flex-row md:items-end md:justify-between">
      <form
        class="flex flex-1 items-end gap-2 md:max-w-xl"
        role="search"
        @submit.prevent="runSearch"
      >
        <SvTextInput
          id="served-client-search"
          v-model="search"
          label="Search by client name"
          type="search"
          autocomplete="off"
          class="flex-1"
        />
        <button
          type="submit"
          class="sv-focus-ring mb-5 inline-flex min-h-sv-touch items-center gap-2 rounded-control bg-sv-brand px-4 text-sm font-bold text-sv-text-on-brand hover:bg-sv-brand-hover"
        >
          <SvIconSearch
            aria-hidden="true"
            class="h-4 w-4"
          />Search
        </button>
      </form>
      <div
        role="group"
        aria-label="SMS eligibility"
        class="flex flex-wrap gap-2"
      >
        <button
          v-for="option in filters"
          :key="option.value"
          type="button"
          :aria-pressed="eligibility === option.value"
          class="sv-focus-ring inline-flex min-h-sv-touch items-center rounded-full border px-4 text-sm font-semibold transition-colors duration-sv-fast motion-reduce:transition-none"
          :class="eligibility === option.value ? 'border-sv-brand bg-sv-selected-bg text-sv-selected-fg' : 'border-sv-border bg-sv-surface-raised text-sv-text-secondary hover:border-sv-brand'"
          @click="eligibility = option.value"
        >
          {{ option.label }}
        </button>
      </div>
    </div>

    <SvStateBoundary
      :state="state"
      :error-message="store.errors.clients ?? undefined"
      empty-message="No personally served clients match this search."
      @retry="load"
    >
      <p
        v-if="visible.length === 0"
        class="rounded-card border border-dashed border-sv-border-strong p-5 text-sm text-sv-text-muted"
      >
        No clients on this page match that eligibility filter.
      </p>
      <ul
        v-else
        class="grid gap-4 md:grid-cols-2 lg:grid-cols-3"
        aria-label="Served clients"
      >
        <li
          v-for="client in visible"
          :key="client.id"
          class="sv-client-card flex flex-col rounded-card border border-sv-border p-5 shadow-card"
        >
          <div class="flex items-start justify-between gap-3">
            <SvMaskedIdentity
              :name="client.full_name"
              :phone-masked="client.phone_masked"
            />
            <SvStatusBadge
              size="sm"
              sr-prefix="SMS:"
              :label="client.sms_eligible ? 'SMS eligible' : humanizeStatus(client.sms_consent)"
              :tone="client.sms_eligible ? 'success' : 'neutral'"
            />
          </div>
          <dl class="mt-5 grid grid-cols-2 gap-3">
            <div class="rounded-control bg-sv-surface-subtle p-3">
              <dt class="text-xs text-sv-text-muted">
                Visits with you
              </dt>
              <dd class="mt-1 font-display text-xl font-bold text-sv-text-heading">
                {{ client.visit_count }}
              </dd>
            </div>
            <div class="rounded-control bg-sv-surface-subtle p-3">
              <dt class="text-xs text-sv-text-muted">
                Last served
              </dt>
              <dd class="mt-1 text-sm font-semibold text-sv-text-heading">
                {{ nairobiDate(client.last_served_at) }}
              </dd>
            </div>
          </dl>
          <div class="mt-4 flex flex-wrap gap-2">
            <span
              v-for="service in client.services"
              :key="service.id"
              class="rounded-full border border-sv-border bg-sv-surface-raised px-2.5 py-1 text-xs text-sv-text-secondary"
            >{{ service.name }}</span>
          </div>
          <div class="mt-auto pt-4">
            <RouterLink
              v-if="client.sms_eligible"
              class="sv-focus-ring inline-flex min-h-sv-touch items-center text-sm font-semibold text-sv-link underline"
              :to="{ name: 'personnel.messages-compose', query: { client: client.id } }"
            >
              Message {{ client.full_name.split(' ')[0] }} in Servana
            </RouterLink>
            <p
              v-else
              class="text-xs leading-5 text-sv-text-muted"
            >
              SMS is unavailable until the client gives consent.
            </p>
          </div>
        </li>
      </ul>
      <SvPagination
        v-if="meta && meta.last_page > 1"
        class="mt-6"
        :current-page="meta.current_page"
        :last-page="meta.last_page"
        :total="meta.total"
        :per-page="meta.per_page"
        label="Served clients pages"
        @change="goTo"
      />
    </SvStateBoundary>
  </section>
</template>

<style scoped>
.sv-client-card {
  background: linear-gradient(170deg, color-mix(in srgb, var(--sv-color-brand-secondary) 7%, var(--sv-color-surface-raised)), var(--sv-color-surface-raised) 55%);
}
</style>

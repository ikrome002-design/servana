<script setup lang="ts">
import axios from 'axios';
import { computed, onMounted, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import SvButton from '@/components/ui/SvButton.vue';
import SvCard from '@/components/ui/SvCard.vue';
import SvOperationalHero from '@/components/ui/SvOperationalHero.vue';
import SvSelect from '@/components/ui/SvSelect.vue';
import SvStateBoundary from '@/components/ui/SvStateBoundary.vue';
import SvTextArea from '@/components/ui/SvTextArea.vue';
import { apiClient } from '@/services/apiClient';
import { useNotificationStore } from '@/stores/notificationStore';
import { useQueueStore } from '@/stores/queueStore';
import type { QueueEntry, ServiceEligibility } from '@/types/models';
import { queueStatusLabel, waitEstimateLabel } from '@/utils/queue';

const route = useRoute();
const router = useRouter();
const queue = useQueueStore();
const notifications = useNotificationStore();
const entry = ref<QueueEntry | null>(null);
const eligible = ref<ServiceEligibility[]>([]);
const personnel = ref('');
const reason = ref('');
const loading = ref(true);
const error = ref(false);
const working = ref(false);
const queueUlid = computed(() => String(route.params.queueUlid));
const state = computed(() => (loading.value ? 'loading' : error.value ? 'error' : entry.value ? 'success' : 'empty'));
const personnelOptions = computed(() => eligible.value
  .filter((item) => item.active && item.staff_profile_id)
  .map((item) => ({ value: item.staff_profile_id as string, label: item.staff_name ?? 'Personnel' })));
const canSubmit = computed(() => personnel.value !== '' && reason.value.trim().length >= 3 && !working.value);

async function load(): Promise<void> {
  loading.value = true;
  error.value = false;
  try {
    entry.value = await queue.fetchEntry(queueUlid.value);
    const serviceId = entry.value.service?.id;
    if (serviceId) {
      const { data } = await apiClient.get<{ data: ServiceEligibility[] }>('/services/' + serviceId + '/eligibility');
      eligible.value = data.data;
    }
  } catch {
    error.value = true;
  } finally {
    loading.value = false;
  }
}

async function submit(): Promise<void> {
  if (!canSubmit.value) return;
  working.value = true;
  try {
    await queue.transfer(queueUlid.value, personnel.value, reason.value.trim());
    notifications.addToast({ type: 'success', message: 'Queue entry transferred.' });
    await router.push({ name: 'front-office.queue' });
  } catch (err: unknown) {
    const message = axios.isAxiosError(err) && err.apiError ? err.apiError.message : 'Unable to transfer this queue entry.';
    notifications.addToast({ type: 'error', message });
  } finally {
    working.value = false;
  }
}

onMounted(load);
</script>

<template>
  <section
    class="mx-auto max-w-5xl"
    data-testid="front-office-queue-transfer"
  >
    <SvOperationalHero
      eyebrow="Operational continuity"
      title="Transfer queue entry"
      description="Reassign the client safely. The server rechecks branch scope, service eligibility and the queue state before committing the transfer."
    >
      <template #actions>
        <RouterLink
          class="sv-focus-ring inline-flex min-h-sv-touch items-center rounded-control border border-white/25 bg-white/10 px-4 py-2 text-sm font-semibold text-white"
          :to="{ name: 'front-office.queue' }"
        >
          Back to queue
        </RouterLink>
      </template>
    </SvOperationalHero>

    <div class="mt-5">
      <SvStateBoundary
        :state="state"
        error-message="We couldn’t load this queue entry."
        empty-message="This queue entry is unavailable."
        @retry="load"
      >
        <div
          v-if="entry"
          class="grid gap-4 lg:grid-cols-[0.8fr_1.2fr]"
        >
          <SvCard
            as="aside"
            padding="lg"
          >
            <p class="text-xs font-semibold uppercase tracking-wide text-text-muted">
              Transfer subject
            </p>
            <h2 class="mt-2 font-display text-2xl font-bold text-heading">
              {{ entry.client?.full_name ?? 'Client' }}
            </h2>
            <dl class="mt-5 space-y-4 text-sm">
              <div>
                <dt class="text-text-muted">
                  Service
                </dt><dd class="mt-1 font-semibold text-heading">
                  {{ entry.service?.name }}
                </dd>
              </div>
              <div>
                <dt class="text-text-muted">
                  Queue status
                </dt><dd class="mt-1 font-semibold text-heading">
                  {{ queueStatusLabel(entry.status) }}
                </dd>
              </div>
              <div>
                <dt class="text-text-muted">
                  Current wait
                </dt><dd class="mt-1 font-semibold text-heading">
                  {{ waitEstimateLabel(entry.estimated_wait.effective_minutes) }}
                </dd>
              </div>
              <div>
                <dt class="text-text-muted">
                  Position
                </dt><dd class="mt-1 font-semibold text-heading">
                  #{{ entry.position }}
                </dd>
              </div>
            </dl>
          </SvCard>

          <SvCard
            as="form"
            padding="lg"
            @submit.prevent="submit"
          >
            <p class="text-xs font-semibold uppercase tracking-wide text-text-muted">
              New assignment
            </p>
            <h2 class="mt-1 font-display text-xl font-bold text-heading">
              Choose eligible personnel
            </h2>
            <p class="mt-2 text-sm text-text-muted">
              Only the server-provided eligible list is shown. A reason is required for the append-only operational record.
            </p>
            <div class="mt-5 space-y-4">
              <SvSelect
                id="transfer-personnel"
                v-model="personnel"
                label="Transfer to"
                :options="personnelOptions"
                required
              />
              <SvTextArea
                id="transfer-reason"
                v-model="reason"
                label="Transfer reason"
                required
                hint="At least 3 characters. Do not include client contact details."
              />
            </div>
            <div class="mt-6 flex flex-wrap justify-end gap-2 border-t border-sv-border pt-4">
              <RouterLink
                class="sv-focus-ring inline-flex min-h-sv-touch items-center rounded-control px-4 text-sm font-semibold text-heading"
                :to="{ name: 'front-office.queue' }"
              >
                Cancel
              </RouterLink>
              <SvButton
                type="submit"
                :disabled="!canSubmit"
              >
                {{ working ? 'Transferring…' : 'Confirm transfer' }}
              </SvButton>
            </div>
          </SvCard>
        </div>
      </SvStateBoundary>
    </div>
  </section>
</template>

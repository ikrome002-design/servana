<script setup lang="ts">
import axios from 'axios';
import { computed, onMounted, ref } from 'vue';
import { useRoute } from 'vue-router';
import PermissionGate from '@/components/auth/PermissionGate.vue';
import SvButton from '@/components/ui/SvButton.vue';
import SvCard from '@/components/ui/SvCard.vue';
import SvInput from '@/components/ui/SvInput.vue';
import SvStateBoundary from '@/components/ui/SvStateBoundary.vue';
import SvTextarea from '@/components/ui/SvTextarea.vue';
import { useForm } from '@/composables/useForm';
import { useClientStore } from '@/stores/clientStore';
import { useNotificationStore } from '@/stores/notificationStore';
import type { Client, SmsConsentState } from '@/types/models';

// Client detail / edit + SMS consent (Plan §35; Phase 15A). Contact is shown
// masked; an edit may change the phone (re-checked for duplicates server-side).
// The API is the boundary; consent capture sends no SMS in this phase.
const route = useRoute();
const clients = useClientStore();
const notifications = useNotificationStore();

const clientId = computed(() => String(route.params.id));
const client = ref<Client | null>(null);
const loading = ref(true);
const loadError = ref(false);
const editing = ref(false);

const boundaryState = computed<'loading' | 'empty' | 'error' | 'success'>(() => {
  if (loading.value) return 'loading';
  if (loadError.value) return 'error';
  if (!client.value) return 'empty';
  return 'success';
});

const form = useForm<{ full_name: string; phone: string; email: string; notes: string }>({
  full_name: '',
  phone: '',
  email: '',
  notes: '',
});

async function load(): Promise<void> {
  loading.value = true;
  loadError.value = false;
  try {
    client.value = await clients.fetchClient(clientId.value);
    form.values.full_name = client.value.full_name;
    form.values.phone = '';
    form.values.email = '';
    form.values.notes = client.value.notes ?? '';
  } catch {
    loadError.value = true;
  } finally {
    loading.value = false;
  }
}

onMounted(load);

const save = form.handleSubmit(async (values) => {
  const payload: Record<string, unknown> = { full_name: values.full_name, notes: values.notes };
  if (values.phone.trim() !== '') payload.phone = values.phone;
  if (values.email.trim() !== '') payload.email = values.email;
  try {
    client.value = await clients.updateClient(clientId.value, payload);
    notifications.addToast({ type: 'success', message: 'Client updated.' });
    editing.value = false;
  } catch (err: unknown) {
    if (axios.isAxiosError(err) && err.apiError) {
      if (err.apiError.code === 'validation_failed') {
        form.mergeServerErrors(err.apiError);
        return;
      }
      notifications.addToast({ type: 'error', message: err.apiError.message });
      return;
    }
    notifications.addToast({ type: 'error', message: 'Something went wrong.' });
  }
});

async function setConsent(state: SmsConsentState): Promise<void> {
  try {
    await clients.changeConsent(clientId.value, state);
    if (client.value) client.value = { ...client.value, sms_consent: state };
    notifications.addToast({ type: 'success', message: 'Consent updated.' });
  } catch {
    notifications.addToast({ type: 'error', message: 'Unable to update consent.' });
  }
}
</script>

<template>
  <section class="mx-auto w-full max-w-lg p-4 md:p-6">
    <SvStateBoundary
      :state="boundaryState"
      empty-message="Client not found."
      error-message="We couldn’t load this client."
      @retry="load"
    >
      <div v-if="client">
        <h1 class="font-display text-2xl font-bold text-brand-deep">
          {{ client.full_name }}
        </h1>
        <p class="mt-1 text-sm text-text-muted">
          {{ client.phone_masked }}
          <span v-if="client.has_email"> · {{ client.email_masked }}</span>
        </p>

        <!-- SMS consent -->
        <SvCard
          as="div"
          padding="md"
          class="mt-6"
        >
          <h2 class="font-display text-base font-semibold text-brand-deep">
            SMS consent
          </h2>
          <p
            class="mt-1 text-sm text-text-muted"
            data-testid="consent-state"
          >
            Current: {{ client.sms_consent ?? 'not set' }}
          </p>
          <PermissionGate permission="client.update">
            <div class="mt-3 flex gap-2">
              <SvButton
                :variant="client.sms_consent === 'opted_in' ? 'primary' : 'ghost'"
                data-testid="opt-in"
                @click="setConsent('opted_in')"
              >
                Opted in
              </SvButton>
              <SvButton
                :variant="client.sms_consent === 'opted_out' ? 'primary' : 'ghost'"
                data-testid="opt-out"
                @click="setConsent('opted_out')"
              >
                Opted out
              </SvButton>
            </div>
          </PermissionGate>
        </SvCard>

        <!-- Edit -->
        <PermissionGate permission="client.update">
          <div class="mt-6">
            <SvButton
              v-if="!editing"
              variant="secondary"
              data-testid="edit-client"
              @click="editing = true"
            >
              Edit client
            </SvButton>

            <SvCard
              v-else
              as="div"
              padding="lg"
            >
              <form
                class="flex flex-col gap-4"
                novalidate
                @submit.prevent="save"
              >
                <SvInput
                  id="full_name"
                  v-model="form.values.full_name"
                  label="Full name"
                  required
                  :errors="form.errors.full_name"
                />
                <SvInput
                  id="phone"
                  v-model="form.values.phone"
                  label="New phone (leave blank to keep current)"
                  :errors="form.errors.phone"
                />
                <SvInput
                  id="email"
                  v-model="form.values.email"
                  label="New email (leave blank to keep current)"
                  type="email"
                  :errors="form.errors.email"
                />
                <SvTextarea
                  id="notes"
                  v-model="form.values.notes"
                  label="Notes"
                  :errors="form.errors.notes"
                />
                <div class="flex justify-end gap-2">
                  <SvButton
                    type="button"
                    variant="ghost"
                    @click="editing = false"
                  >
                    Cancel
                  </SvButton>
                  <SvButton
                    type="submit"
                    variant="primary"
                    :loading="form.submitting.value"
                  >
                    Save changes
                  </SvButton>
                </div>
              </form>
            </SvCard>
          </div>
        </PermissionGate>
      </div>
    </SvStateBoundary>
  </section>
</template>

<script setup lang="ts">
import axios from 'axios';
import { ref } from 'vue';
import { useRouter } from 'vue-router';
import SvButton from '@/components/ui/SvButton.vue';
import SvCard from '@/components/ui/SvCard.vue';
import SvOperationalHero from '@/components/ui/SvOperationalHero.vue';
import SvTextInput from '@/components/ui/SvTextInput.vue';
import SvTextArea from '@/components/ui/SvTextArea.vue';
import { useForm } from '@/composables/useForm';
import { useClientStore } from '@/stores/clientStore';
import { useNotificationStore } from '@/stores/notificationStore';

// Create a client (Plan §35; Phase 15A). A same-branch duplicate phone returns a
// deterministic 409 with the existing client's id — surfaced here as a link to the
// existing record rather than a silent failure. The API is the boundary.
const clients = useClientStore();
const notifications = useNotificationStore();
const router = useRouter();

const duplicateId = ref<string | null>(null);

const form = useForm<{ full_name: string; phone: string; email: string; notes: string }>({
  full_name: '',
  phone: '',
  email: '',
  notes: '',
});

const submit = form.handleSubmit(async (values) => {
  duplicateId.value = null;
  try {
    const created = await clients.createClient({
      full_name: values.full_name,
      phone: values.phone,
      email: values.email === '' ? undefined : values.email,
      notes: values.notes === '' ? undefined : values.notes,
    });
    notifications.addToast({ type: 'success', message: 'Client created.' });
    await router.push({ name: 'front-office.client-detail', params: { clientUlid: created.id } });
  } catch (err: unknown) {
    if (axios.isAxiosError(err) && err.apiError) {
      if (err.apiError.code === 'duplicate_client') {
        const existing = err.apiError.meta?.client_id;
        duplicateId.value = typeof existing === 'string' ? existing : null;
        return;
      }
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
</script>

<template>
  <section class="mx-auto w-full max-w-5xl">
    <SvOperationalHero
      eyebrow="Welcome a new client"
      title="Add a client"
      description="Capture only the details needed for branch service. The server normalizes the phone and prevents a second client record for the same branch."
    >
      <template #actions>
        <RouterLink
          class="sv-focus-ring inline-flex min-h-sv-touch items-center rounded-control border border-white/25 bg-white/10 px-4 py-2 text-sm font-semibold text-white"
          :to="{ name: 'front-office.clients' }"
        >
          Back to clients
        </RouterLink>
      </template>
    </SvOperationalHero>

    <div
      v-if="duplicateId"
      role="alert"
      class="mt-5 rounded-control border border-sv-warning-border bg-sv-warning-bg p-4 text-sm text-sv-warning-fg shadow-card"
      data-testid="duplicate-warning"
    >
      A client with this phone number already exists in this branch.
      <RouterLink
        :to="{ name: 'front-office.client-detail', params: { clientUlid: duplicateId } }"
        class="font-semibold text-heading underline"
      >
        Open the existing client
      </RouterLink>.
    </div>

    <SvCard
      as="div"
      padding="lg"
      class="mx-auto mt-5 max-w-2xl border-t-4 border-t-sv-brand"
    >
      <form
        class="flex flex-col gap-4"
        novalidate
        @submit.prevent="submit"
      >
        <SvTextInput
          id="full_name"
          v-model="form.values.full_name"
          label="Full name"
          required
          :errors="form.errors.full_name"
        />
        <SvTextInput
          id="phone"
          v-model="form.values.phone"
          label="Phone"
          help="Stored securely; shown masked after saving."
          placeholder="0712 345 678"
          required
          :errors="form.errors.phone"
        />
        <SvTextInput
          id="email"
          v-model="form.values.email"
          label="Email (optional)"
          type="email"
          :errors="form.errors.email"
        />
        <SvTextArea
          id="notes"
          v-model="form.values.notes"
          label="Notes (optional)"
          :errors="form.errors.notes"
        />
        <SvButton
          type="submit"
          variant="primary"
          :loading="form.submitting.value"
        >
          Create client
        </SvButton>
      </form>
    </SvCard>
  </section>
</template>

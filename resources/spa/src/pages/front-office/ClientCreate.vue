<script setup lang="ts">
import axios from 'axios';
import { ref } from 'vue';
import { useRouter } from 'vue-router';
import SvButton from '@/components/ui/SvButton.vue';
import SvCard from '@/components/ui/SvCard.vue';
import SvInput from '@/components/ui/SvInput.vue';
import SvTextarea from '@/components/ui/SvTextarea.vue';
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
    await router.push({ name: 'front-office.clients.detail', params: { id: created.id } });
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
  <section class="mx-auto w-full max-w-lg p-4 md:p-6">
    <h1 class="font-display text-2xl font-bold text-heading">
      Add a client
    </h1>

    <div
      v-if="duplicateId"
      role="alert"
      class="mt-4 rounded-control border border-warning/40 bg-warning/10 p-3 text-sm text-text"
      data-testid="duplicate-warning"
    >
      A client with this phone number already exists in this branch.
      <RouterLink
        :to="{ name: 'front-office.clients.detail', params: { id: duplicateId } }"
        class="font-semibold text-heading underline"
      >
        Open the existing client
      </RouterLink>.
    </div>

    <SvCard
      as="div"
      padding="lg"
      class="mt-6"
    >
      <form
        class="flex flex-col gap-4"
        novalidate
        @submit.prevent="submit"
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
          label="Phone"
          hint="Stored securely; shown masked after saving."
          placeholder="0712 345 678"
          required
          :errors="form.errors.phone"
        />
        <SvInput
          id="email"
          v-model="form.values.email"
          label="Email (optional)"
          type="email"
          :errors="form.errors.email"
        />
        <SvTextarea
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

<script setup lang="ts">
import axios from 'axios';
import { useRouter } from 'vue-router';
import SvButton from '@/components/ui/SvButton.vue';
import SvCard from '@/components/ui/SvCard.vue';
import SvInput from '@/components/ui/SvInput.vue';
import { useForm } from '@/composables/useForm';
import { useBranchStore } from '@/stores/branchStore';
import { useNotificationStore } from '@/stores/notificationStore';

// Create a branch (Scope §3.3). Merchant Administrator authority — the API is
// the boundary; this page is reached from the admin-only "Add branch" action.
const router = useRouter();
const branches = useBranchStore();
const notifications = useNotificationStore();

const form = useForm<{ name: string; code: string; town: string; phone: string }>({
  name: '',
  code: '',
  town: '',
  phone: '',
});

const submit = form.handleSubmit(async (values) => {
  try {
    await branches.createBranch(values);
    notifications.addToast({ type: 'success', message: 'Branch created.' });
    await router.push({ name: 'branch.list' });
  } catch (err: unknown) {
    if (axios.isAxiosError(err) && err.apiError) {
      if (err.apiError.code === 'validation_failed') {
        form.mergeServerErrors(err.apiError);
        return;
      }
      notifications.addToast({ type: 'error', message: err.apiError.message });
      return;
    }
    notifications.addToast({ type: 'error', message: 'Something went wrong. Please try again.' });
  }
});
</script>

<template>
  <section class="mx-auto w-full max-w-lg p-4 md:p-6">
    <h1 class="font-display text-2xl font-bold text-brand-deep">
      Add a branch
    </h1>

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
          id="name"
          v-model="form.values.name"
          label="Branch name"
          placeholder="Kilimani Branch"
          required
          :errors="form.errors.name"
        />
        <SvInput
          id="code"
          v-model="form.values.code"
          label="Branch code"
          hint="Unique within your business; used on invoice numbers."
          placeholder="KIL001"
          required
          :errors="form.errors.code"
        />
        <SvInput
          id="town"
          v-model="form.values.town"
          label="Town"
          :errors="form.errors.town"
        />
        <SvInput
          id="phone"
          v-model="form.values.phone"
          label="Phone"
          :errors="form.errors.phone"
        />
        <SvButton
          type="submit"
          variant="primary"
          :loading="form.submitting.value"
        >
          Create branch
        </SvButton>
      </form>
    </SvCard>
  </section>
</template>

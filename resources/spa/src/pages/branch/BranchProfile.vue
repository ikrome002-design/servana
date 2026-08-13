<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue';
import SvButton from '@/components/ui/SvButton.vue';
import SvCard from '@/components/ui/SvCard.vue';
import SvPageHeader from '@/components/ui/SvPageHeader.vue';
import SvStateBoundary from '@/components/ui/SvStateBoundary.vue';
import SvTextInput from '@/components/ui/SvTextInput.vue';
import { useBranchExperienceStore } from '@/stores/branchExperienceStore';
import { useNotificationStore } from '@/stores/notificationStore';

const store = useBranchExperienceStore();
const notifications = useNotificationStore();
const saving = ref(false);
const form = reactive({ name: '', code: '', address: '', town: '', phone: '', email: '', business_category: '' });
const state = computed(() => store.loading ? 'loading' : store.error ? 'error' : store.overview ? 'success' : 'empty');

async function load(): Promise<void> {
  await store.fetchOverview();
  const branch = store.overview?.branch;
  if (!branch) return;
  Object.assign(form, {
    name: branch.name, code: branch.code, address: branch.address ?? '', town: branch.town ?? '',
    phone: branch.phone ?? '', email: branch.email ?? '', business_category: branch.business_category ?? '',
  });
}

async function save(): Promise<void> {
  saving.value = true;
  try {
    await store.updateProfile({
      name: form.name,
      code: form.code,
      address: form.address || null,
      town: form.town || null,
      phone: form.phone || null,
      email: form.email || null,
      business_category: form.business_category || null,
    });
    notifications.addToast({ type: 'success', message: 'Branch profile updated.' });
  } catch {
    notifications.addToast({ type: 'error', message: 'The branch profile could not be updated.' });
  } finally {
    saving.value = false;
  }
}

onMounted(load);
</script>

<template>
  <section
    class="mx-auto max-w-4xl"
    data-testid="branch-profile"
  >
    <SvPageHeader
      title="Branch profile"
      eyebrow="Branch setup"
      description="Maintain the public operating details for your assigned branch. Branch creation and ownership stay with the Merchant Administrator."
    />
    <SvStateBoundary
      :state="state"
      :error-message="store.error ?? undefined"
      empty-message="No assigned branch is available."
      @retry="load"
    >
      <SvCard
        as="form"
        class="grid gap-5 md:grid-cols-2"
        @submit.prevent="save"
      >
        <SvTextInput
          id="branch-name"
          v-model="form.name"
          label="Branch name"
          required
        />
        <SvTextInput
          id="branch-code"
          v-model="form.code"
          label="Branch code"
          required
        />
        <SvTextInput
          id="branch-address"
          v-model="form.address"
          label="Address"
        />
        <SvTextInput
          id="branch-town"
          v-model="form.town"
          label="Town"
        />
        <SvTextInput
          id="branch-phone"
          v-model="form.phone"
          label="Phone"
        />
        <SvTextInput
          id="branch-email"
          v-model="form.email"
          label="Email"
          type="email"
        />
        <SvTextInput
          id="branch-category"
          v-model="form.business_category"
          class="md:col-span-2"
          label="Business category"
        />
        <div class="flex flex-wrap gap-3 md:col-span-2">
          <SvButton
            type="submit"
            variant="primary"
            :loading="saving"
          >
            Save profile
          </SvButton>
          <RouterLink
            v-if="store.branchId"
            class="sv-focus-ring inline-flex min-h-sv-touch items-center rounded-control px-4 py-2 text-sm font-semibold text-heading underline"
            :to="{ name: 'branch.operating-hours', params: { id: store.branchId } }"
          >
            Set operating hours
          </RouterLink>
          <RouterLink
            class="sv-focus-ring inline-flex min-h-sv-touch items-center rounded-control px-4 py-2 text-sm font-semibold text-heading underline"
            :to="{ name: 'branch.branch-calendar' }"
          >
            Manage calendar
          </RouterLink>
        </div>
      </SvCard>
    </SvStateBoundary>
  </section>
</template>

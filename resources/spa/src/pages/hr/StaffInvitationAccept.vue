<script setup lang="ts">
import axios from 'axios';
import { ref } from 'vue';
import { useRoute } from 'vue-router';
import SvButton from '@/components/ui/SvButton.vue';
import SvCard from '@/components/ui/SvCard.vue';
import SvTextInput from '@/components/ui/SvTextInput.vue';
import { useForm } from '@/composables/useForm';
import { primeCsrfCookie } from '@/services/apiClient';
import { useStaffStore } from '@/stores/staffStore';

// Public staff invitation acceptance (Scope §3.4). The raw token comes from the
// emailed link (?token=). On success the user signs in via Magic Link — no
// password. Invalid/expired tokens get a uniform error (no enumeration).
const route = useRoute();
const staff = useStaffStore();

const token = typeof route.query.token === 'string' ? route.query.token : '';
const accepted = ref(false);
const failed = ref(false);

const form = useForm<{ first_name: string; last_name: string; phone: string }>({
  first_name: '',
  last_name: '',
  phone: '',
});

const submit = form.handleSubmit(async (values) => {
  failed.value = false;
  try {
    await primeCsrfCookie();
    await staff.acceptInvitation({ token, ...values });
    accepted.value = true;
  } catch (err: unknown) {
    if (axios.isAxiosError(err) && err.apiError) {
      if (err.apiError.code === 'validation_failed') {
        form.mergeServerErrors(err.apiError);
        return;
      }
      // invalid_or_expired_invitation and anything else → uniform failure state.
      failed.value = true;
      return;
    }
    failed.value = true;
  }
});
</script>

<template>
  <SvCard
    as="section"
    padding="lg"
    class="w-full max-w-md"
  >
    <template v-if="accepted">
      <h1 class="font-display text-2xl font-extrabold text-heading">
        You're all set
      </h1>
      <p
        class="mt-2 text-sm text-text-muted"
        data-testid="accept-success"
      >
        Your account is ready. Head to sign-in and we'll email you a secure Magic Link —
        no password needed.
      </p>
      <RouterLink
        :to="{ name: 'auth.login' }"
        class="mt-6 inline-block font-semibold text-heading underline"
      >
        Go to sign-in
      </RouterLink>
    </template>

    <template v-else-if="token === '' || failed">
      <h1 class="font-display text-2xl font-extrabold text-heading">
        Invitation problem
      </h1>
      <p
        class="mt-2 text-sm text-text-muted"
        data-testid="accept-error"
      >
        This invitation is invalid or has expired. Please ask your manager for a new one.
      </p>
    </template>

    <template v-else>
      <h1 class="font-display text-2xl font-extrabold text-heading">
        Accept your invitation
      </h1>
      <p class="mt-2 text-sm text-text-muted">
        Tell us a little about you to finish setting up.
      </p>

      <form
        class="mt-6 flex flex-col gap-4"
        novalidate
        @submit.prevent="submit"
      >
        <SvTextInput
          id="first_name"
          v-model="form.values.first_name"
          label="First name"
          required
          :errors="form.errors.first_name"
        />
        <SvTextInput
          id="last_name"
          v-model="form.values.last_name"
          label="Last name"
          required
          :errors="form.errors.last_name"
        />
        <SvTextInput
          id="phone"
          v-model="form.values.phone"
          label="Phone"
          placeholder="+254 7XX XXX XXX"
          required
          :errors="form.errors.phone"
        />
        <SvButton
          type="submit"
          variant="primary"
          :loading="form.submitting.value"
        >
          Accept invitation
        </SvButton>
      </form>
    </template>
  </SvCard>
</template>

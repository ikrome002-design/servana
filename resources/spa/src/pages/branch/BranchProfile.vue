<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue';
import SvButton from '@/components/ui/SvButton.vue';
import SvPageHeader from '@/components/ui/SvPageHeader.vue';
import SvStateBoundary from '@/components/ui/SvStateBoundary.vue';
import SvTextInput from '@/components/ui/SvTextInput.vue';
import { navigationIcon } from '@/navigation/navigationIcons';
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
  <!--
    COR-UI08-UI12-VIS-001 — branch identity, editorially. An identity banner (the branch's own name,
    code, town and contact as read from the server, with the two schedule entry points) sits above
    the editable profile grouped into identity, location and contact. Creation and ownership stay
    with the Merchant Administrator; the same fields and save path as before.
  -->
  <section
    class="sv-r-page"
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
      <div class="sv-r-stack">
        <section
          v-if="store.overview"
          class="sv-r-panel sv-r-panel--tonal sv-r-tone-green"
          aria-label="Branch identity"
        >
          <div class="flex flex-wrap items-center gap-5">
            <span
              aria-hidden="true"
              class="sv-r-icon sv-r-icon--lg sv-r-tone-green"
            >
              <component :is="navigationIcon('branch')" />
            </span>
            <div class="min-w-0 flex-1">
              <p class="sv-r-eyebrow">
                Your assigned branch
              </p>
              <p class="sv-r-title-lg mt-1 break-words">
                {{ store.overview.branch.name }}
              </p>
            </div>
            <div class="flex flex-wrap gap-2">
              <RouterLink
                v-if="store.branchId"
                class="sv-r-action sv-r-action--quiet sv-focus-ring"
                :to="{ name: 'branch.operating-hours', params: { id: store.branchId } }"
              >
                Set operating hours
              </RouterLink>
              <RouterLink
                class="sv-r-action sv-r-action--quiet sv-focus-ring"
                :to="{ name: 'branch.branch-calendar' }"
              >
                Manage calendar
              </RouterLink>
            </div>
          </div>
          <dl class="sv-r-strip mt-5">
            <div>
              <dt>Code</dt>
              <dd>{{ store.overview.branch.code }}</dd>
            </div>
            <div>
              <dt>Town</dt>
              <dd>{{ store.overview.branch.town ?? 'Not set' }}</dd>
            </div>
            <div>
              <dt>Phone</dt>
              <dd>{{ store.overview.branch.phone ?? 'Not set' }}</dd>
            </div>
            <div>
              <dt>Email</dt>
              <dd>{{ store.overview.branch.email ?? 'Not set' }}</dd>
            </div>
          </dl>
        </section>

        <form
          class="sv-r-panel flex flex-col gap-6"
          @submit.prevent="save"
        >
          <fieldset>
            <legend class="sv-r-eyebrow sv-r-eyebrow--dot sv-r-tone-green mb-3">
              Identity
            </legend>
            <div class="grid gap-5 md:grid-cols-2">
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
                id="branch-category"
                v-model="form.business_category"
                class="md:col-span-2"
                label="Business category"
              />
            </div>
          </fieldset>
          <fieldset class="border-t border-sv-border pt-5">
            <legend class="sv-r-eyebrow sv-r-eyebrow--dot sv-r-tone-teal mb-3">
              Location
            </legend>
            <div class="grid gap-5 md:grid-cols-2">
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
            </div>
          </fieldset>
          <fieldset class="border-t border-sv-border pt-5">
            <legend class="sv-r-eyebrow sv-r-eyebrow--dot sv-r-tone-warm mb-3">
              Contact
            </legend>
            <div class="grid gap-5 md:grid-cols-2">
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
            </div>
          </fieldset>
          <div class="flex flex-wrap gap-3 border-t border-sv-border pt-5">
            <SvButton
              type="submit"
              variant="primary"
              :loading="saving"
            >
              Save profile
            </SvButton>
          </div>
        </form>
      </div>
    </SvStateBoundary>
  </section>
</template>
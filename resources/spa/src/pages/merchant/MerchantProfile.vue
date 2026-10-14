<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue';
import SvFileUpload, { type UploadedFileResource } from '@/components/files/SvFileUpload.vue';
import SvButton from '@/components/ui/SvButton.vue';
import SvStateBoundary from '@/components/ui/SvStateBoundary.vue';
import { navigationIcon } from '@/navigation/navigationIcons';
import { useMerchantProfileStore } from '@/stores/merchantProfileStore';
import { useNotificationStore } from '@/stores/notificationStore';
import { usePermissionStore } from '@/stores/permissionStore';
import type { MerchantProfileUpdate } from '@/types/models';
import SvPageHeader from '@/components/ui/SvPageHeader.vue';
import { formatDate } from '@/utils/dates';

/**
 * Merchant business profile (REM-SCR-002A; Plan §27.3 Merchant Administrator "merchant profile").
 *
 * Frontend visibility is UX only — `merchant.profile.view` / `merchant.profile.update` and
 * MerchantProfilePolicy are the boundary, and billing read-only is enforced by
 * EnsureBillingMutable regardless of what this screen renders.
 */
const store = useMerchantProfileStore();
const permissions = usePermissionStore();
const notifications = useNotificationStore();

const loadFailed = ref(false);
const logoHref = ref<string | null>(null);
const logoPreviewHref = ref<string | null>(null);

// UX only — EnsurePermission + MerchantProfilePolicy are the boundary.
const canUpdate = computed(() => permissions.can('merchant.profile.update'));

const boundaryState = computed<'loading' | 'error' | 'success'>(() => {
  if (store.loading) return 'loading';
  if (loadFailed.value) return 'error';
  return 'success';
});

/** The editable subset — deliberately the same seven fields the backend allowlist accepts. */
const form = reactive<Required<MerchantProfileUpdate>>({
  business_category: '',
  contact_email: null,
  contact_phone: '',
  receipt_display_name: null,
  address: null,
  town: null,
  timezone: 'Africa/Nairobi',
});

function hydrate(): void {
  const profile = store.profile;
  if (!profile) return;
  form.business_category = profile.business_category ?? '';
  form.contact_email = profile.contact_email;
  form.contact_phone = profile.contact_phone ?? '';
  form.receipt_display_name = profile.receipt_display_name;
  form.address = profile.address;
  form.town = profile.town;
  form.timezone = profile.timezone;
}

onMounted(async () => {
  try {
    await store.fetchProfile();
    hydrate();
    logoHref.value = await store.logoUrl();
  } catch {
    loadFailed.value = true;
  }
});

watch(() => store.profile?.logo?.id, async () => {
  logoHref.value = await store.logoUrl();
});

onBeforeUnmount(() => {
  if (logoPreviewHref.value) URL.revokeObjectURL(logoPreviewHref.value);
});

async function uploadLogo(file: File, purpose: string): Promise<UploadedFileResource> {
  if (logoPreviewHref.value) URL.revokeObjectURL(logoPreviewHref.value);
  logoPreviewHref.value = URL.createObjectURL(file);
  return store.uploadLogo(file, purpose);
}

function onLogoUploaded(file: UploadedFileResource): void {
  notifications.addToast({
    type: 'success',
    message: file.lifecycle_status === 'available'
      ? 'Business logo replaced.'
      : 'Logo uploaded and queued for security scanning.',
  });
  if (file.lifecycle_status === 'available') void store.fetchProfile();
}

function onLogoRejected(message: string): void {
  notifications.addToast({ type: 'error', message });
}

function errorsFor(field: string): string[] {
  return store.fieldErrors[field] ?? [];
}

async function save(): Promise<void> {
  try {
    await store.updateProfile({ ...form });
    hydrate();
    notifications.addToast({ type: 'success', message: 'Business profile saved.' });
  } catch {
    notifications.addToast({
      type: 'error',
      message: 'Could not save the business profile. Check the highlighted fields.',
    });
  }
}
</script>

<template>
  <!--
    COR-UI08-UI12-VIS-001 — the merchant identity profile.

    An identity card (the merchant's own approved logo, the read-only business name and country
    owned by registration and governance, the logo upload and its replacement history) sits beside
    the editable profile, which is grouped into the three things a client actually meets: how to
    reach the business, what its receipts say, and where it is. Same fields, ids, validation and
    permissions as before.
  -->
  <section class="sv-r-page">
    <SvPageHeader
      title="Business profile"
      eyebrow="Merchant"
      description="Your business details as they appear to clients and on receipts."
    />

    <SvStateBoundary
      :state="boundaryState"
      error-message="We could not load your business profile."
    >
      <div
        v-if="store.profile"
        class="sv-r-grid lg:grid-cols-[minmax(0,22rem)_minmax(0,1fr)] lg:items-start"
      >
        <!-- Identity card -------------------------------------------------------------------- -->
        <aside
          class="sv-r-panel sv-r-panel--tonal sv-r-tone-warm sv-r-sticky"
          aria-label="Business identity"
        >
          <div class="flex flex-col items-center text-center">
            <img
              v-if="logoPreviewHref || logoHref"
              :src="logoPreviewHref ?? logoHref ?? undefined"
              alt="Current merchant logo preview"
              class="h-28 w-28 rounded-3xl border border-sv-border bg-white object-contain p-3 shadow-card"
            >
            <span
              v-else
              aria-hidden="true"
              class="sv-r-icon sv-r-icon--xl sv-r-tone-warm"
            >
              <component :is="navigationIcon('merchant')" />
            </span>
            <!-- Read-only identity: owned by registration and platform governance, not this screen. -->
            <dl class="mt-4 w-full">
              <div>
                <dt class="sr-only">
                  Business name
                </dt>
                <dd class="sv-r-title-lg break-words">
                  {{ store.profile.merchant?.name ?? '—' }}
                </dd>
              </div>
              <div class="mt-2 flex justify-center">
                <dt class="sr-only">
                  Country
                </dt>
                <dd class="sv-r-chip">
                  {{ store.profile.country }}
                </dd>
              </div>
            </dl>
          </div>

          <div class="mt-6 border-t border-sv-border pt-5">
            <h2 class="sv-r-eyebrow">
              Logo
            </h2>
            <p
              v-if="!store.profile.logo"
              class="sv-r-muted mt-1"
            >
              No logo uploaded yet.
            </p>
            <p
              v-else-if="store.profile.logo"
              class="mt-1 text-sm text-text"
            >
              <a
                v-if="logoHref"
                :href="logoHref"
                class="underline text-heading"
                rel="noopener"
              >{{ store.profile.logo.filename }}</a>
              <span v-else>{{ store.profile.logo.filename }}</span>
            </p>
            <SvFileUpload
              v-if="canUpdate"
              class="mt-4"
              purpose="merchant_logo"
              label="Upload or replace merchant logo"
              :uploader="uploadLogo"
              @uploaded="onLogoUploaded"
              @rejected="onLogoRejected"
            />
            <div
              v-if="store.profile.logo_history.length > 0"
              class="mt-4"
            >
              <h3 class="text-sm font-semibold text-heading">
                Replacement history
              </h3>
              <ul
                class="mt-2 space-y-1 text-sm text-text-muted"
                aria-label="Logo replacement history"
              >
                <li
                  v-for="entry in store.profile.logo_history"
                  :key="entry.id"
                >
                  {{ entry.filename }}<span v-if="entry.available_at"> · {{ formatDate(entry.available_at) }}</span>
                </li>
              </ul>
            </div>
          </div>
        </aside>

        <!-- Editable profile -------------------------------------------------------------------- -->
        <form
          class="sv-r-panel flex flex-col gap-6"
          novalidate
          @submit.prevent="save"
        >
          <fieldset class="sv-r-stack-sm">
            <legend class="sv-r-eyebrow sv-r-eyebrow--dot sv-r-tone-warm mb-3">
              Business
            </legend>
            <div class="flex flex-col gap-1">
              <label
                for="mp-business-category"
                class="text-sm font-medium text-text"
              >Business category</label>
              <input
                id="mp-business-category"
                v-model="form.business_category"
                type="text"
                :disabled="!canUpdate"
                :aria-invalid="errorsFor('business_category').length > 0"
                :aria-describedby="errorsFor('business_category').length ? 'mp-business-category-error' : undefined"
                class="min-h-[44px] w-full rounded-control border border-sv-border-input bg-surface px-3 text-text"
              >
              <p
                v-if="errorsFor('business_category').length"
                id="mp-business-category-error"
                class="text-sm text-sv-error-fg"
              >
                {{ errorsFor('business_category')[0] }}
              </p>
            </div>
          </fieldset>

          <fieldset class="border-t border-sv-border pt-5">
            <legend class="sv-r-eyebrow sv-r-eyebrow--dot sv-r-tone-teal mb-3">
              How clients reach you
            </legend>
            <div class="grid gap-4 md:grid-cols-2">
              <div class="flex flex-col gap-1">
                <label
                  for="mp-contact-phone"
                  class="text-sm font-medium text-text"
                >Contact phone</label>
                <input
                  id="mp-contact-phone"
                  v-model="form.contact_phone"
                  type="tel"
                  :disabled="!canUpdate"
                  :aria-invalid="errorsFor('contact_phone').length > 0"
                  :aria-describedby="errorsFor('contact_phone').length ? 'mp-contact-phone-error' : undefined"
                  class="min-h-[44px] w-full rounded-control border border-sv-border-input bg-surface px-3 text-text"
                >
                <p
                  v-if="errorsFor('contact_phone').length"
                  id="mp-contact-phone-error"
                  class="text-sm text-sv-error-fg"
                >
                  {{ errorsFor('contact_phone')[0] }}
                </p>
              </div>

              <div class="flex flex-col gap-1">
                <label
                  for="mp-contact-email"
                  class="text-sm font-medium text-text"
                >Contact email</label>
                <input
                  id="mp-contact-email"
                  v-model="form.contact_email"
                  type="email"
                  :disabled="!canUpdate"
                  :aria-invalid="errorsFor('contact_email').length > 0"
                  :aria-describedby="errorsFor('contact_email').length ? 'mp-contact-email-error' : undefined"
                  class="min-h-[44px] w-full rounded-control border border-sv-border-input bg-surface px-3 text-text"
                >
                <p
                  v-if="errorsFor('contact_email').length"
                  id="mp-contact-email-error"
                  class="text-sm text-sv-error-fg"
                >
                  {{ errorsFor('contact_email')[0] }}
                </p>
              </div>
            </div>
          </fieldset>

          <fieldset class="border-t border-sv-border pt-5">
            <legend class="sv-r-eyebrow sv-r-eyebrow--dot sv-r-tone-sun mb-3">
              What receipts say
            </legend>
            <div class="flex flex-col gap-1">
              <label
                for="mp-receipt-display-name"
                class="text-sm font-medium text-text"
              >Receipt display name</label>
              <input
                id="mp-receipt-display-name"
                v-model="form.receipt_display_name"
                type="text"
                :disabled="!canUpdate"
                class="min-h-[44px] w-full rounded-control border border-sv-border-input bg-surface px-3 text-text"
              >
              <p class="text-xs text-sv-text-muted">
                Shown on receipts when it differs from the business name.
              </p>
            </div>
          </fieldset>

          <fieldset class="border-t border-sv-border pt-5">
            <legend class="sv-r-eyebrow sv-r-eyebrow--dot sv-r-tone-green mb-3">
              Where you are
            </legend>
            <div class="grid gap-4 md:grid-cols-2">
              <div class="flex flex-col gap-1 md:col-span-2">
                <label
                  for="mp-address"
                  class="text-sm font-medium text-text"
                >Address</label>
                <input
                  id="mp-address"
                  v-model="form.address"
                  type="text"
                  :disabled="!canUpdate"
                  class="min-h-[44px] w-full rounded-control border border-sv-border-input bg-surface px-3 text-text"
                >
              </div>

              <div class="flex flex-col gap-1">
                <label
                  for="mp-town"
                  class="text-sm font-medium text-text"
                >Town</label>
                <input
                  id="mp-town"
                  v-model="form.town"
                  type="text"
                  :disabled="!canUpdate"
                  :aria-invalid="errorsFor('town').length > 0"
                  class="min-h-[44px] w-full rounded-control border border-sv-border-input bg-surface px-3 text-text"
                >
                <p
                  v-if="errorsFor('town').length"
                  class="text-sm text-sv-error-fg"
                >
                  {{ errorsFor('town')[0] }}
                </p>
              </div>

              <div class="flex flex-col gap-1">
                <label
                  for="mp-timezone"
                  class="text-sm font-medium text-text"
                >Timezone</label>
                <input
                  id="mp-timezone"
                  v-model="form.timezone"
                  type="text"
                  :disabled="!canUpdate"
                  :aria-invalid="errorsFor('timezone').length > 0"
                  :aria-describedby="errorsFor('timezone').length ? 'mp-timezone-error' : undefined"
                  class="min-h-[44px] w-full rounded-control border border-sv-border-input bg-surface px-3 text-text"
                >
                <p
                  v-if="errorsFor('timezone').length"
                  id="mp-timezone-error"
                  class="text-sm text-sv-error-fg"
                >
                  {{ errorsFor('timezone')[0] }}
                </p>
              </div>
            </div>
          </fieldset>

          <div
            v-if="canUpdate"
            class="flex justify-end border-t border-sv-border pt-5"
          >
            <SvButton
              type="submit"
              :disabled="store.saving"
            >
              {{ store.saving ? 'Saving…' : 'Save changes' }}
            </SvButton>
          </div>
          <p
            v-else
            class="text-sm text-sv-text-muted"
          >
            You have view-only access to the business profile.
          </p>
        </form>
      </div>
    </SvStateBoundary>
  </section>
</template>

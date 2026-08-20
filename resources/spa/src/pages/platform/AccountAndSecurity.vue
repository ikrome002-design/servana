<script setup lang="ts">
/**
 * Account and Security — Super Administrator contract page §5.4.22 (Phase UI-08).
 *
 * The signed-in user's OWN identity, MFA, sessions and preferences. Nothing on this page reaches
 * another account: every endpoint it calls is own-scope by construction — `/me`, `/auth/mfa*`,
 * `/auth/sessions*` and `/auth/preferences` take no user identifier, and the session routes 404 a
 * foreign ULID rather than 403 it, so the API cannot be used to confirm that another user exists.
 * Management of OTHER Citrus platform users lives exclusively on `/platform-access`.
 *
 * ## What is deliberately absent
 *
 * No password field, no OTP login, no passkey or WebAuthn enrollment — Servana authenticates with
 * Magic Links only, and no password exists to change. No merchant-role assignment, no membership
 * control, and no way to edit another user.
 *
 * ## MFA policy is not a user setting
 *
 * A platform role's MFA requirement is decided server-side. This page can enroll, confirm and
 * rotate recovery codes; there is no control that could weaken or opt out of the requirement, and
 * the page says so rather than leaving the absence to be inferred.
 *
 * ## Recovery is enumeration-safe
 *
 * Recovery-code regeneration operates on the caller's own credential and needs a fresh step-up. It
 * never accepts an email or identifier, so it cannot be used to probe whether another platform
 * account exists.
 *
 * ## Preferences
 *
 * Theme is a real, server-persisted preference (`/auth/preferences`, ADR-021). Display density and
 * a timezone override have no field on the user record, and notification preferences belong to the
 * gated Notifications runtime — none of the three is rendered as a working control.
 */
import { computed, onMounted, ref } from 'vue';
import SvAlert from '@/components/ui/SvAlert.vue';
import SvButton from '@/components/ui/SvButton.vue';
import SvCard from '@/components/ui/SvCard.vue';
import SvConfirmDialog from '@/components/ui/SvConfirmDialog.vue';
import SvDataTable from '@/components/ui/SvDataTable.vue';
import SvOperationalHero from '@/components/ui/SvOperationalHero.vue';
import SvPageHeader from '@/components/ui/SvPageHeader.vue';
import SvResponsiveRecordList from '@/components/ui/SvResponsiveRecordList.vue';
import SvStatusBadge from '@/components/ui/SvStatusBadge.vue';
import SvTextInput from '@/components/ui/SvTextInput.vue';
import SvThemeToggle from '@/components/ui/SvThemeToggle.vue';
import type { SvColumn, SvDataState } from '@/components/ui/dataContract';
import { useAuthStore } from '@/stores/authStore';
import { useSessionFamilyStore, type HostSessionView } from '@/stores/sessionFamilyStore';

const props = withDefaults(defineProps<{ experience?: 'platform' | 'merchant' | 'branch' | 'hr' | 'finance' | 'front-office' }>(), {
  experience: 'platform',
});

const auth = useAuthStore();
const sessions = useSessionFamilyStore();

const enrollmentSecret = ref<{ secret: string; otpauth_uri: string } | null>(null);
const enrollmentCode = ref('');
const recoveryCodes = ref<string[] | null>(null);
const mfaBusy = ref(false);
const mfaError = ref<string | null>(null);
const confirmRegenerate = ref(false);
const pendingRevoke = ref<HostSessionView | null>(null);

const user = computed(() => auth.user);
const mfa = computed(() => auth.mfa);
const mfaEnrolled = computed(() => mfa.value?.enrolled === true);
const mfaConfirmed = computed(() => mfa.value?.confirmed === true);
const pageDescription = computed(() => {
  if (props.experience === 'merchant') return 'Your own identity, Magic Link security, active account sessions and display preference. Staff access is managed from the Merchant staff lifecycle page.';
  if (props.experience === 'branch') return 'Your own identity, Magic Link security, assigned-branch context, active sessions and display preference.';
  if (props.experience === 'hr') return 'Your own identity, Magic Link security, active Human Resource branch context, sessions and display preference.';
  if (props.experience === 'finance') return 'Your own identity, mandatory Finance MFA, active branch context, sessions and display preference. Financial policy remains server-owned.';
  if (props.experience === 'front-office') return 'Your own Magic Link identity, active Front Office branch context, sessions and display preference. This page cannot change staff access or branch operations.';
  return 'Your own identity, sign-in security, active sessions and display preferences. Other platform users are managed under internal platform access.';
});
const mfaPolicyNote = computed(() => {
  if (props.experience === 'merchant') return 'Two-factor authentication is required for the Merchant Administrator account and cannot be turned off or weakened from this page.';
  if (props.experience === 'branch') return 'This page can strengthen your own sign-in with two-factor authentication. It never changes branch permissions or another user’s security.';
  if (props.experience === 'hr') return 'This page can strengthen your own sign-in with two-factor authentication. It cannot change your Human Resource role, branch assignment or another user’s access.';
  if (props.experience === 'finance') return 'Two-factor authentication is mandatory for Finance access. This page cannot weaken step-up, maker/checker, period-lock or payment policy.';
  if (props.experience === 'front-office') return 'This page can strengthen your own sign-in with two-factor authentication. It cannot validate payments, change your branch assignment or manage another user’s access.';
  return 'Two-factor authentication is required for platform roles and cannot be turned off or weakened from this page. There is no control here that would lower it, and the server would refuse one.';
});
const scopeNote = computed(() => props.experience === 'platform'
  ? 'This page changes your own account only. It cannot view, edit, suspend or sign out another platform user, and it cannot tell you whether another account exists.'
  : 'This page changes your own identity and sessions only. It cannot view, edit, suspend or sign out another merchant user, and it cannot tell you whether another account exists.');

const experienceLabels: Readonly<Record<string, string>> = {
  super_administrator: 'Super Administrator',
  merchant_administrator: 'Merchant Administrator',
  merchant_audit: 'Audit',
  merchant_branch: 'Branch Manager',
  merchant_finance: 'Finance',
  merchant_front_office: 'Front Office',
  merchant_human_resource: 'Human Resource',
  merchant_personnel: 'Personnel',
};

const sessionColumns: SvColumn<HostSessionView>[] = [
  { key: 'host', label: 'Account host', priority: 'primary', value: (row) => row.host },
  { key: 'account_key', label: 'Experience', priority: 'secondary', value: (row) => experienceLabels[row.account_key] ?? 'Servana account' },
  { key: 'last_activity_at', label: 'Last active', priority: 'secondary', value: (row) => row.last_activity_at },
  { key: 'merchant_name', label: 'Merchant', priority: 'detail', value: (row) => row.merchant_name ?? '—' },
  { key: 'created_at', label: 'Signed in', priority: 'detail', value: (row) => row.created_at ?? '—' },
];

const sessionState = computed<SvDataState>(() => {
  if (sessions.loading) return 'loading';
  if (sessions.error !== null) return 'error';
  return sessions.sessions.length === 0 ? 'empty' : 'idle';
});

onMounted(() => {
  void sessions.fetchSessions();
  void auth.fetchMfaStatus().catch(() => {
    // The bootstrap already carries MFA state; a refresh failure must not blank the page.
  });
});

async function startEnrollment(): Promise<void> {
  mfaBusy.value = true;
  mfaError.value = null;
  try {
    enrollmentSecret.value = await auth.startMfaEnrollment();
  } catch {
    mfaError.value = 'We couldn’t start enrollment. Please try again.';
  } finally {
    mfaBusy.value = false;
  }
}

async function confirmEnrollment(): Promise<void> {
  mfaBusy.value = true;
  mfaError.value = null;
  try {
    recoveryCodes.value = await auth.confirmMfaEnrollment(enrollmentCode.value.trim());
    enrollmentSecret.value = null;
    enrollmentCode.value = '';
  } catch {
    mfaError.value = 'That code wasn’t accepted. Check your authenticator and try again.';
  } finally {
    mfaBusy.value = false;
  }
}

async function regenerate(): Promise<void> {
  confirmRegenerate.value = false;
  mfaBusy.value = true;
  mfaError.value = null;
  try {
    recoveryCodes.value = await auth.regenerateRecoveryCodes();
  } catch {
    mfaError.value = 'We couldn’t replace your recovery codes. A fresh security step-up may be required.';
  } finally {
    mfaBusy.value = false;
  }
}

async function revokePending(): Promise<void> {
  const target = pendingRevoke.value;
  pendingRevoke.value = null;
  if (target !== null) await sessions.revoke(target.id);
}
</script>

<template>
  <div
    class="mx-auto w-full max-w-4xl"
    :data-testid="experience === 'platform' ? 'platform-account-screen' : `${experience}-account-screen`"
  >
    <SvOperationalHero
      v-if="experience === 'front-office'"
      eyebrow="Your Front Office identity"
      title="Account and security"
      :description="pageDescription"
      context="Magic Link · assigned branch"
    />
    <SvPageHeader
      v-else
      title="Account and security"
      eyebrow="Your account"
      :description="pageDescription"
    />

    <!-- Identity ------------------------------------------------------------------------------ -->
    <SvCard
      as="section"
      :class="experience === 'front-office' ? 'mt-5 border-l-4 border-l-sv-brand-secondary' : ''"
      data-testid="account-identity"
    >
      <h2 class="font-display text-lg font-bold text-sv-text-heading">
        Your identity
      </h2>
      <dl class="mt-4 grid grid-cols-1 gap-x-6 gap-y-3 md:grid-cols-2">
        <div>
          <dt class="text-xs font-semibold uppercase tracking-wide text-sv-text-muted">
            Name
          </dt>
          <dd
            class="mt-1 text-sm text-sv-text"
            data-testid="account-name"
          >
            {{ user?.name ?? '—' }}
          </dd>
        </div>
        <div>
          <dt class="text-xs font-semibold uppercase tracking-wide text-sv-text-muted">
            Email
          </dt>
          <dd
            class="mt-1 break-words text-sm text-sv-text"
            data-testid="account-email"
          >
            {{ user?.email ?? '—' }}
          </dd>
        </div>
        <div>
          <dt class="text-xs font-semibold uppercase tracking-wide text-sv-text-muted">
            Account status
          </dt>
          <dd class="mt-1 text-sm text-sv-text">
            <SvStatusBadge
              :label="user?.status ?? 'unknown'"
              :tone="user?.status === 'active' ? 'success' : 'neutral'"
              size="sm"
              sr-prefix="Account status:"
            />
          </dd>
        </div>
        <div>
          <dt class="text-xs font-semibold uppercase tracking-wide text-sv-text-muted">
            Sign-in method
          </dt>
          <dd class="mt-1 text-sm text-sv-text">
            Magic Link
          </dd>
        </div>
      </dl>
      <p class="mt-4 text-xs text-sv-text-muted">
        Servana has no passwords. Your name and email are changed by an administrator, not here —
        there is no self-service endpoint for either.
      </p>
    </SvCard>

    <!-- MFA ----------------------------------------------------------------------------------- -->
    <SvCard
      as="section"
      class="mt-6"
      data-testid="account-mfa"
    >
      <h2 class="font-display text-lg font-bold text-sv-text-heading">
        Two-factor authentication
      </h2>

      <p class="mt-2 text-sm text-sv-text-muted">
        <SvStatusBadge
          :label="mfaConfirmed ? 'Confirmed' : mfaEnrolled ? 'Enrollment started' : 'Not enrolled'"
          :tone="mfaConfirmed ? 'success' : 'warning'"
          size="sm"
          sr-prefix="Two-factor status:"
          data-testid="account-mfa-status"
        />
      </p>

      <p
        v-if="mfaError !== null"
        class="mt-3 text-sm text-sv-error-fg"
        role="alert"
        data-testid="account-mfa-error"
      >
        {{ mfaError }}
      </p>

      <div
        v-if="!mfaConfirmed"
        class="mt-4"
      >
        <SvButton
          v-if="enrollmentSecret === null"
          :loading="mfaBusy"
          data-testid="account-mfa-enroll"
          @click="startEnrollment"
        >
          Set up an authenticator
        </SvButton>

        <form
          v-else
          class="flex flex-col gap-3"
          novalidate
          @submit.prevent="confirmEnrollment"
        >
          <p class="text-sm text-sv-text">
            Add this key to your authenticator app, then enter the six-digit code it shows.
          </p>
          <p
            class="break-all rounded-control border border-sv-border bg-sv-surface-subtle p-3 font-mono text-sm"
            data-testid="account-mfa-secret"
          >
            {{ enrollmentSecret.secret }}
          </p>
          <SvTextInput
            id="account-mfa-code"
            v-model="enrollmentCode"
            label="Six-digit code"
            inputmode="numeric"
            autocomplete="one-time-code"
          />
          <div>
            <SvButton
              type="submit"
              :loading="mfaBusy"
              data-testid="account-mfa-confirm"
            >
              Confirm enrollment
            </SvButton>
          </div>
        </form>
      </div>

      <div
        v-else
        class="mt-4"
      >
        <SvButton
          variant="secondary"
          :loading="mfaBusy"
          data-testid="account-mfa-regenerate"
          @click="confirmRegenerate = true"
        >
          Replace recovery codes
        </SvButton>
      </div>

      <div
        v-if="recoveryCodes !== null"
        class="mt-4 rounded-control border border-sv-warning-border bg-sv-warning-bg p-4"
        data-testid="account-recovery-codes"
      >
        <p class="text-sm font-semibold text-sv-warning-fg">
          Save these recovery codes now. They are shown once and cannot be retrieved later.
        </p>
        <ul class="mt-2 grid grid-cols-2 gap-1 font-mono text-sm">
          <li
            v-for="code in recoveryCodes"
            :key="code"
          >
            {{ code }}
          </li>
        </ul>
      </div>

      <p
        class="mt-4 text-xs text-sv-text-muted"
        data-testid="account-mfa-policy-note"
      >
        {{ mfaPolicyNote }}
      </p>
    </SvCard>

    <!-- Sessions ------------------------------------------------------------------------------ -->
    <SvCard
      as="section"
      class="mt-6"
      data-testid="account-sessions"
    >
      <h2 class="font-display text-lg font-bold text-sv-text-heading">
        Active sessions
      </h2>
      <p class="mt-1 text-sm text-sv-text-muted">
        Every host where you are currently signed in. Ending a session signs that host out
        immediately.
      </p>

      <!--
        UI08-RESP-001: the session table carries a host, an experience, two timestamps and a merchant
        column, which pushed the document past the 768px tablet floor. Desktop gets the table;
        tablet and mobile read the labelled record cards.
      -->
      <div class="mt-4 hidden lg:block">
        <SvDataTable
          :columns="sessionColumns"
          :rows="sessions.sessions"
          :row-key="(row) => row.id"
          caption="Your active sessions"
          :state="sessionState"
          :error-message="sessions.error ?? undefined"
          empty-message="No other active sessions."
          @retry="sessions.fetchSessions()"
        >
          <template #cell:host="{ row }">
            <span class="break-words font-medium text-sv-text">{{ row.host }}</span>
            <span
              v-if="row.is_current"
              class="ml-2 text-xs text-sv-text-muted"
              data-testid="account-session-current"
            >This device</span>
          </template>
        </SvDataTable>
      </div>

      <div class="mt-4 lg:hidden">
        <SvResponsiveRecordList
          :columns="sessionColumns"
          :rows="sessions.sessions"
          :row-key="(row) => row.id"
          caption="Your active sessions"
          :state="sessionState"
          :error-message="sessions.error ?? undefined"
          empty-message="No other active sessions."
          @retry="sessions.fetchSessions()"
        />
      </div>

      <!--
        Revocation is an explicit named action per session rather than a control inside a row the
        user must first select, so the target of "End session" is never ambiguous.
      -->
      <ul
        v-if="sessions.otherSessions.length > 0"
        class="mt-4 flex flex-col gap-2"
      >
        <li
          v-for="session in sessions.otherSessions"
          :key="session.id"
          class="flex flex-wrap items-center justify-between gap-3"
        >
          <span class="break-words text-sm text-sv-text">{{ session.host }}</span>
          <SvButton
            variant="secondary"
            size="sm"
            :loading="sessions.revoking === session.id"
            :data-testid="`account-session-revoke-${session.id}`"
            @click="pendingRevoke = session"
          >
            End session on {{ session.host }}
          </SvButton>
        </li>
      </ul>

      <p class="mt-4 text-xs text-sv-text-muted">
        Session records never include your IP address, device fingerprint or browser string — they
        are not collected for this screen.
      </p>
    </SvCard>

    <!-- Preferences --------------------------------------------------------------------------- -->
    <SvCard
      as="section"
      class="mt-6"
      data-testid="account-preferences"
    >
      <h2 class="font-display text-lg font-bold text-sv-text-heading">
        Display preferences
      </h2>
      <p class="mt-1 text-sm text-sv-text-muted">
        Your theme is stored on your user record, so it follows you to every account host.
      </p>
      <div class="mt-4">
        <SvThemeToggle variant="switch" />
      </div>

      <SvAlert
        severity="info"
        title="Not yet available"
        class="mt-6"
        data-testid="account-preferences-unavailable"
      >
        <ul class="list-disc space-y-1 pl-5">
          <li>
            Display density and a timezone override have no field on the user record. Times are
            shown in the platform's configured timezone.
          </li>
          <li>
            Notification preferences belong to the Notifications runtime, which is not enabled.
            Nothing here silently subscribes or unsubscribes you.
          </li>
        </ul>
      </SvAlert>
    </SvCard>

    <p
      class="mt-6 text-xs text-sv-text-muted"
      data-testid="account-scope-note"
    >
      {{ scopeNote }}
    </p>

    <SvConfirmDialog
      :open="confirmRegenerate"
      title="Replace your recovery codes?"
      message="Your current recovery codes stop working immediately. You will be shown the new set once."
      confirm-label="Replace codes"
      destructive
      @confirm="regenerate"
      @cancel="confirmRegenerate = false"
    />

    <SvConfirmDialog
      :open="pendingRevoke !== null"
      title="End this session?"
      message="That host is signed out immediately. Anyone using it will need a new Magic Link."
      confirm-label="End session"
      destructive
      @confirm="revokePending"
      @cancel="pendingRevoke = null"
    />
  </div>
</template>

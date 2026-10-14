<script setup lang="ts">
/**
 * Feature Flags — Super Administrator contract page §5.4.20 (Phase UI-08).
 *
 * Controls approved platform feature rollout under maker/checker (COR-UI08-001 §12).
 *
 * WHAT A FLAG IS, AND IS NOT. A flag is a RESTRICTIVE rollout control. It cannot grant: the
 * evaluator has no access to permissions, entitlements, billing state or account context, and an
 * active, fully rolled-out, correctly targeted flag still denies with `external_gate_closed`. This
 * page therefore never describes a flag as access, and never offers a flag as a way to reach a
 * gated capability.
 *
 * THE CATALOGUE IS TRUTHFULLY EMPTY. `config/platform-feature-flags.php` allowlists no flag today,
 * and the endpoint says so in `meta.catalogue_is_empty`. The page renders a real empty state that
 * explains why — it does not seed an example flag, fabricate a health metric, or invent a count to
 * look complete. There is deliberately no "create flag" route: a flag must be added to the code
 * allowlist first.
 *
 * MAKER/CHECKER IS STRUCTURAL. A database CHECK refuses a self-approved row even when policy,
 * controller and service are bypassed. This page surfaces the server's refusal rather than
 * restating the rule as a client-side guess.
 */
import { computed, onMounted, ref } from 'vue';
import SvPanel from '@/components/refined/SvPanel.vue';
import SvAlert from '@/components/ui/SvAlert.vue';
import SvButton from '@/components/ui/SvButton.vue';
import SvDateTime from '@/components/ui/SvDateTime.vue';
import SvDialog from '@/components/ui/SvDialog.vue';
import SvEmptyState from '@/components/ui/SvEmptyState.vue';
import SvPageHeader from '@/components/ui/SvPageHeader.vue';
import SvPermissionState from '@/components/ui/SvPermissionState.vue';
import SvSkeleton from '@/components/ui/SvSkeleton.vue';
import SvStatusBadge from '@/components/ui/SvStatusBadge.vue';
import SvTextArea from '@/components/ui/SvTextArea.vue';
import { useCan } from '@/composables/useCan';
import { usePlatformFeatureFlagStore, type FeatureFlagRow } from '@/stores/platformFeatureFlagStore';
import { statusLabel } from '@/utils/statusLabel';
import { SvIconClock, SvIconFlag, SvIconWarning } from '@/design-system/icons';

const store = usePlatformFeatureFlagStore();
const { can } = useCan();

const canView = computed(() => can('platform.settings.view'));
const canUpdate = computed(() => can('platform.settings.update'));

const catalogueIsEmpty = computed(() => store.catalogue?.catalogue_is_empty === true || store.flags.length === 0);

onMounted(() => {
  if (canView.value) void store.load();
});

function resolveMessage(error: unknown, fallback: string): string {
  return (error as { apiError?: { message?: string } }).apiError?.message ?? fallback;
}

/**
 * A flag key CONTAINS dots. Rendering it needs no special handling, but every path that carries it
 * does — the store encodes it, and nothing here splits it on `.`, which is exactly the trap that
 * made `config()->set('…flags.my.flag')` build a nested config path during Increment 6.
 */
const flagKeyOf = (row: FeatureFlagRow): string =>
  typeof row.definition === 'string' ? row.definition : String(row.state?.id ?? '');

// ---------------------------------------------------------------------------------------------
// Change request
// ---------------------------------------------------------------------------------------------

const changeOpen = ref(false);
const changeFlagKey = ref('');
const proposedConfiguration = ref('');
const impactStatement = ref('');
const rollbackPlan = ref('');
const healthCriterion = ref('');
const changeReason = ref('');
const changeSubmitting = ref(false);
const changeError = ref<string | null>(null);

function openChange(flagKey: string): void {
  changeFlagKey.value = flagKey;
  proposedConfiguration.value = '{\n  "state": "active",\n  "rollout_basis_points": 0\n}';
  impactStatement.value = '';
  rollbackPlan.value = '';
  healthCriterion.value = '';
  changeReason.value = '';
  changeError.value = null;
  changeOpen.value = true;
}

async function submitChange(): Promise<void> {
  if (changeSubmitting.value) return;
  changeSubmitting.value = true;
  changeError.value = null;

  let parsed: Record<string, unknown>;
  try {
    parsed = JSON.parse(proposedConfiguration.value) as Record<string, unknown>;
  } catch {
    changeError.value = 'The proposed configuration is not valid JSON.';
    changeSubmitting.value = false;
    return;
  }

  try {
    await store.requestChange(changeFlagKey.value, {
      proposed_configuration: parsed,
      impact_statement: impactStatement.value,
      rollback_plan: rollbackPlan.value,
      health_criterion: healthCriterion.value,
      reason: changeReason.value,
    });
    changeOpen.value = false;
  } catch (error) {
    changeError.value = resolveMessage(error, 'The server refused this change request.');
  } finally {
    changeSubmitting.value = false;
  }
}

// ---------------------------------------------------------------------------------------------
// Pause (kill switch)
// ---------------------------------------------------------------------------------------------

const pauseFlagKey = ref<string | null>(null);
const pauseReason = ref('');
const pauseSubmitting = ref(false);
const pauseError = ref<string | null>(null);

function openPause(flagKey: string): void {
  pauseFlagKey.value = flagKey;
  pauseReason.value = '';
  pauseError.value = null;
}

async function confirmPause(): Promise<void> {
  if (pauseSubmitting.value || pauseFlagKey.value === null) return;
  pauseSubmitting.value = true;
  pauseError.value = null;

  try {
    await store.pause(pauseFlagKey.value, pauseReason.value);
    pauseFlagKey.value = null;
  } catch (error) {
    pauseError.value = resolveMessage(error, 'Unable to pause this rollout.');
  } finally {
    pauseSubmitting.value = false;
  }
}

const stateTone = (state: string | null | undefined): 'success' | 'warning' | 'neutral' | 'info' => {
  if (state === 'active') return 'success';
  if (state === 'paused') return 'warning';
  if (state === 'scheduled') return 'info';
  return 'neutral';
};
</script>

<template>
  <!--
    COR-UI08-UI12-VIS-001: rollout, not toggles. Each flag is a card whose rollout is DRAWN from the
    server's basis points (a labelled meter, not a switch), with its environment and state as
    facts; the safety rules that bound every flag sit in the rail beside the catalogue, and the
    append-only change history runs down a timeline under it.
  -->
  <div
    class="sv-r-page"
    data-testid="feature-flags-screen"
  >
    <SvPageHeader
      title="Feature flags"
      eyebrow="Platform administration"
      description="Control the rollout of approved platform features. A flag can only restrict — it can never grant access, bypass an entitlement, change billing, or open an external gate."
    />

    <SvPermissionState v-if="!canView" />

    <template v-else>
      <p
        class="sv-r-muted -mt-2 mb-5"
        data-testid="flags-last-refreshed"
      >
        Last refreshed
        <SvDateTime :value="store.lastRefreshed" />
      </p>

      <SvAlert
        v-if="store.error"
        severity="error"
        title="We could not load the feature-flag catalogue"
        class="mb-6"
      >
        <p>{{ store.error }}</p>
        <SvButton
          variant="secondary"
          size="sm"
          class="mt-3"
          data-testid="flags-retry"
          @click="store.load()"
        >
          Try again
        </SvButton>
      </SvAlert>

      <SvSkeleton
        v-else-if="store.loading"
        shape="text"
        :lines="4"
        label="Loading feature flags"
      />

      <div
        v-else
        class="sv-r-grid sv-r-split"
      >
        <div class="sv-r-stack min-w-0">
          <!--
            The truthful empty state. The catalogue is empty because no flag is allowlisted in code —
            that is the real reason, and it is stated. No example flag is seeded, no health figure is
            fabricated, and no "create flag" control is offered, because creating one requires a code
            change first.
          -->
          <template v-if="catalogueIsEmpty">
            <SvEmptyState
              title="No feature flag is currently allowlisted"
              description="A flag exists only after it is added to the platform's code allowlist. Nothing is scheduled, active or paused, so there is no rollout to control here yet."
              data-testid="flags-empty"
            />
          </template>

          <!-- Populated catalogue -------------------------------------------------------------- -->
          <ul
            v-else
            class="sv-r-stack-sm"
            data-testid="flags-list"
          >
            <li
              v-for="row in store.flags"
              :key="flagKeyOf(row)"
              class="sv-r-record"
            >
              <!-- UI-16 (UI16-VIS-019): the flag key leads as a heading with its facts as labelled chips. -->
              <div class="flex flex-wrap items-start justify-between gap-3">
                <div class="flex min-w-0 items-start gap-3">
                  <span
                    aria-hidden="true"
                    class="sv-r-icon sv-r-icon--lg sv-r-tone-warm"
                  >
                    <SvIconFlag />
                  </span>
                  <div class="min-w-0">
                    <p class="break-words font-mono text-base font-semibold text-sv-text-heading">
                      {{ flagKeyOf(row) }}
                    </p>
                    <p class="mt-2 flex flex-wrap gap-2">
                      <span class="sv-r-chip">Environment {{ row.state?.environment ?? 'not configured' }}</span>
                      <span class="sv-r-chip">Rollout {{ row.state?.rollout_basis_points ?? 0 }} basis points</span>
                    </p>
                  </div>
                </div>
                <SvStatusBadge
                  :label="statusLabel(String(row.effective_state ?? row.state?.state ?? 'inactive'))"
                  :tone="stateTone(String(row.effective_state ?? row.state?.state ?? ''))"
                  sr-prefix="Rollout state:"
                />
              </div>

              <div
                class="sv-r-meter mt-4"
                aria-hidden="true"
              >
                <span :style="{ width: `${Math.min(100, (row.state?.rollout_basis_points ?? 0) / 100)}%` }" />
              </div>

              <p class="sv-r-muted mt-3">
                A flag never grants access. Permissions, entitlements, billing state and external gates
                are evaluated independently and always win.
              </p>

              <div
                v-if="canUpdate"
                class="mt-4 flex flex-wrap gap-2 border-t border-sv-border pt-4"
              >
                <SvButton
                  variant="secondary"
                  size="sm"
                  :data-testid="`flags-history-${flagKeyOf(row)}`"
                  @click="store.openFlag(flagKeyOf(row))"
                >
                  View history
                </SvButton>
                <SvButton
                  variant="secondary"
                  size="sm"
                  :data-testid="`flags-change-${flagKeyOf(row)}`"
                  @click="openChange(flagKeyOf(row))"
                >
                  Request a change
                </SvButton>
                <SvButton
                  variant="destructive"
                  size="sm"
                  :data-testid="`flags-pause-${flagKeyOf(row)}`"
                  @click="openPause(flagKeyOf(row))"
                >
                  Pause rollout
                </SvButton>
              </div>
            </li>
          </ul>

          <!-- History -------------------------------------------------------------------------- -->
          <SvPanel
            v-if="store.history"
            eyebrow="Append-only"
            title="Change history"
            description="Append-only. A history row is never updated or deleted."
            tone="teal"
          >
            <ol
              v-if="Array.isArray(store.history)"
              class="sv-r-steps"
              data-testid="flags-history"
            >
              <li
                v-for="entry in store.history"
                :key="String(entry.id)"
                class="sv-r-step"
              >
                <span
                  aria-hidden="true"
                  class="sv-r-step__marker"
                >
                  <SvIconClock class="h-4 w-4" />
                </span>
                <div class="pt-1.5">
                  <p class="font-semibold text-sv-text">
                    {{ entry.action }}
                  </p>
                  <p class="sv-r-muted">
                    <SvDateTime :value="entry.created_at" /> · {{ entry.reason ?? 'No reason recorded' }}
                  </p>
                </div>
              </li>
            </ol>
          </SvPanel>
        </div>

        <aside
          class="sv-r-stack-sm sv-r-sticky"
          aria-label="Feature-flag safety rules"
        >
          <div class="sv-r-note sv-r-tone-alert">
            <div class="flex items-center gap-3">
              <span
                aria-hidden="true"
                class="sv-r-icon sv-r-tone-alert"
              >
                <SvIconWarning />
              </span>
              <p class="sv-r-note__title">
                Safety rules
              </p>
            </div>
            <ul class="sv-r-note__body list-disc space-y-1 pl-5">
              <li>A flag can only restrict; it never grants access or bypasses an entitlement.</li>
              <li>A change is proposed, then approved by a different platform administrator.</li>
              <li>Pausing a rollout takes effect immediately.</li>
            </ul>
          </div>
          <div
            v-if="store.catalogue"
            class="sv-r-note sv-r-tone-teal"
            data-testid="flags-catalogue-meta"
          >
            <p class="sv-r-note__title">
              Catalogue
            </p>
            <dl class="sv-r-facts mt-1">
              <div>
                <dt>Environment</dt>
                <dd>{{ store.catalogue.environment }}</dd>
              </div>
              <div>
                <dt>Allowlisted flags</dt>
                <dd class="sv-numeric">
                  {{ store.catalogue.catalogue_size }}
                </dd>
              </div>
              <div>
                <dt>Catalogue source</dt>
                <dd class="break-words">
                  {{ store.catalogue.catalogue_source }}
                </dd>
              </div>
            </dl>
            <p class="sv-r-muted mt-3">
              {{ store.catalogue.note }}
            </p>
          </div>
        </aside>
      </div>
    </template>
    <!-- Change request ---------------------------------------------------------------------- -->
    <SvDialog
      :open="changeOpen"
      title="Request a feature-flag change"
      description="A change is proposed, not applied. A different platform administrator must approve it — the database refuses a self-approved change even if every other check is bypassed."
      size="lg"
      persistent
      @close="changeOpen = false"
    >
      <div class="space-y-4">
        <SvTextArea
          id="flags-configuration"
          v-model="proposedConfiguration"
          label="Proposed configuration (JSON)"
          :rows="6"
          required
        />
        <SvTextArea
          id="flags-impact"
          v-model="impactStatement"
          label="Impact"
          :rows="3"
          help="Plain language: which screens and APIs change, and for whom."
          required
        />
        <SvTextArea
          id="flags-rollback"
          v-model="rollbackPlan"
          label="Rollback plan"
          :rows="3"
          help="How this is reversed if the health criterion is not met."
          required
        />
        <SvTextArea
          id="flags-health"
          v-model="healthCriterion"
          label="Health criterion"
          :rows="2"
          help="The measurable condition that says this rollout is working."
          required
        />
        <SvTextArea
          id="flags-reason"
          v-model="changeReason"
          label="Reason"
          :rows="2"
          required
        />

        <SvAlert
          v-if="changeError"
          severity="error"
          data-testid="flags-change-error"
        >
          <p>{{ changeError }}</p>
        </SvAlert>

        <p class="text-xs text-sv-text-muted">
          This change requires multi-factor authentication and a fresh step-up. You cannot approve
          your own request.
        </p>
      </div>

      <template #footer>
        <SvButton
          variant="ghost"
          @click="changeOpen = false"
        >
          Cancel
        </SvButton>
        <SvButton
          variant="primary"
          :loading="changeSubmitting"
          loading-label="Submitting"
          data-testid="flags-change-submit"
          @click="submitChange"
        >
          Submit for approval
        </SvButton>
      </template>
    </SvDialog>

    <!-- Pause ------------------------------------------------------------------------------- -->
    <SvDialog
      :open="pauseFlagKey !== null"
      title="Pause this rollout?"
      description="Pausing takes effect immediately and does not require a second approver — stopping a rollout is always allowed to be faster than starting one."
      persistent
      @close="pauseFlagKey = null"
    >
      <div class="space-y-4">
        <SvTextArea
          id="flags-pause-reason"
          v-model="pauseReason"
          label="Reason"
          :rows="3"
          help="Recorded on the append-only history."
          required
        />
        <SvAlert
          v-if="pauseError"
          severity="error"
          data-testid="flags-pause-error"
        >
          <p>{{ pauseError }}</p>
        </SvAlert>
      </div>

      <template #footer>
        <SvButton
          variant="ghost"
          @click="pauseFlagKey = null"
        >
          Cancel
        </SvButton>
        <SvButton
          variant="destructive"
          :loading="pauseSubmitting"
          loading-label="Pausing"
          data-testid="flags-pause-submit"
          @click="confirmPause"
        >
          Pause rollout
        </SvButton>
      </template>
    </SvDialog>
  </div>
</template>

<script setup lang="ts">
import { onMounted, ref, watch } from 'vue';
import SvCard from '@/components/ui/SvCard.vue';
import SvSelect from '@/components/ui/SvSelect.vue';
import { useCan } from '@/composables/useCan';
import { apiClient } from '@/services/apiClient';
import type { MerchantRole } from '@/types/enums';
import type { PermissionPreview } from '@/types/models';

// HR / Merchant Admin permission preview (Plan §10.3): show what a target role
// would hold BEFORE making a change. Read-only — never a self-escalation vector;
// the API also gates this to staff managers.
const { canAny } = useCan();
const allowed = canAny(['staff.invite', 'branches.manage_users_lifecycle']);

const roleOptions: { value: MerchantRole; label: string }[] = [
  { value: 'branch_manager', label: 'Branch Manager' },
  { value: 'hr', label: 'Human Resource' },
  { value: 'finance', label: 'Finance' },
  { value: 'front_office', label: 'Front Office' },
  { value: 'personnel', label: 'Personnel' },
  { value: 'audit', label: 'Audit' },
];

const role = ref<MerchantRole>('finance');
const preview = ref<PermissionPreview | null>(null);
const loading = ref(false);

async function load(): Promise<void> {
  if (!allowed) {
    return;
  }
  loading.value = true;
  try {
    const { data } = await apiClient.get<{ data: PermissionPreview }>(
      '/hr/permission-preview',
      { params: { role: role.value } },
    );
    preview.value = data.data;
  } finally {
    loading.value = false;
  }
}

watch(role, () => {
  void load();
});

onMounted(() => {
  void load();
});
</script>

<template>
  <section class="p-4 md:p-6">
    <h1 class="font-display text-2xl font-bold text-brand-deep">
      Permission preview
    </h1>

    <SvCard
      v-if="!allowed"
      as="div"
      padding="md"
      class="mt-6"
    >
      <p
        class="text-text-muted"
        data-testid="no-access"
      >
        You do not have access to permission previews.
      </p>
    </SvCard>

    <template v-else>
      <div class="mt-6 max-w-sm">
        <SvSelect
          id="preview-role"
          v-model="role"
          label="Role"
          :options="roleOptions"
        />
      </div>

      <div
        v-if="preview"
        class="mt-6 grid gap-6 md:grid-cols-2"
      >
        <SvCard
          as="article"
          padding="md"
        >
          <h2 class="font-display text-base font-semibold text-brand-deep">
            Default permissions
          </h2>
          <ul class="mt-3 flex flex-col gap-1">
            <li
              v-for="key in preview.default_grants"
              :key="key"
              class="font-mono text-sm text-text"
            >
              {{ key }}
            </li>
          </ul>
        </SvCard>

        <SvCard
          as="article"
          padding="md"
        >
          <h2 class="font-display text-base font-semibold text-brand-deep">
            Grantable (override) permissions
          </h2>
          <p
            v-if="preview.grantable.length === 0"
            class="mt-3 text-sm text-text-muted"
          >
            None — this role has no optional permissions.
          </p>
          <ul
            v-else
            class="mt-3 flex flex-col gap-1"
          >
            <li
              v-for="key in preview.grantable"
              :key="key"
              class="font-mono text-sm text-text"
            >
              {{ key }}
            </li>
          </ul>
        </SvCard>
      </div>
    </template>
  </section>
</template>

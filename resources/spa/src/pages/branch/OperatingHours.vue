<script setup lang="ts">
import { onMounted, ref } from 'vue';
import { useRoute } from 'vue-router';
import SvButton from '@/components/ui/SvButton.vue';
import SvCard from '@/components/ui/SvCard.vue';
import { useBranchStore } from '@/stores/branchStore';
import { useNotificationStore } from '@/stores/notificationStore';
import type { BranchOperatingHour } from '@/types/models';

// Weekly operating hours (Scope §3.3). One row per weekday.
const route = useRoute();
const branches = useBranchStore();
const notifications = useNotificationStore();
const id = String(route.params.id ?? '');

const weekdays = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
const saving = ref(false);

const hours = ref<BranchOperatingHour[]>(
  Array.from({ length: 7 }, (_, weekday) => ({
    weekday,
    opens_at: '09:00',
    closes_at: '17:00',
    is_closed: weekday === 0,
    break_start: null,
    break_end: null,
  })),
);

onMounted(async () => {
  await branches.fetchOperatingHours(id);
  for (const existing of branches.operatingHours) {
    const row = hours.value.find((h) => h.weekday === existing.weekday);
    if (row) Object.assign(row, existing);
  }
});

async function save(): Promise<void> {
  saving.value = true;
  try {
    await branches.saveOperatingHours(id, hours.value);
    notifications.addToast({ type: 'success', message: 'Operating hours saved.' });
  } catch {
    notifications.addToast({ type: 'error', message: 'Could not save operating hours.' });
  } finally {
    saving.value = false;
  }
}
</script>

<template>
  <section class="mx-auto w-full max-w-2xl p-4 md:p-6">
    <h1 class="font-display text-2xl font-bold text-brand-deep">
      Operating hours
    </h1>

    <SvCard
      as="div"
      padding="lg"
      class="mt-6"
    >
      <form
        class="flex flex-col gap-3"
        novalidate
        @submit.prevent="save"
      >
        <fieldset
          v-for="row in hours"
          :key="row.weekday"
          class="flex flex-wrap items-center gap-3 border-b border-border pb-3"
        >
          <legend class="sr-only">
            {{ weekdays[row.weekday] }}
          </legend>
          <span class="w-24 text-sm font-medium text-text">{{ weekdays[row.weekday] }}</span>
          <label class="flex items-center gap-2 text-sm">
            <input
              v-model="row.is_closed"
              type="checkbox"
              class="h-4 w-4"
            >
            Closed
          </label>
          <label class="flex items-center gap-1 text-sm">
            <span class="sr-only">{{ weekdays[row.weekday] }} opens at</span>
            <input
              v-model="row.opens_at"
              type="time"
              :disabled="row.is_closed"
              class="min-h-[44px] rounded-control border border-border bg-surface px-2"
            >
          </label>
          <label class="flex items-center gap-1 text-sm">
            <span class="sr-only">{{ weekdays[row.weekday] }} closes at</span>
            <input
              v-model="row.closes_at"
              type="time"
              :disabled="row.is_closed"
              class="min-h-[44px] rounded-control border border-border bg-surface px-2"
            >
          </label>
        </fieldset>

        <SvButton
          type="submit"
          variant="primary"
          :loading="saving"
        >
          Save hours
        </SvButton>
      </form>
    </SvCard>
  </section>
</template>

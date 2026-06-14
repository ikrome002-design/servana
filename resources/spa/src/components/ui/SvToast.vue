<script setup lang="ts">
import { onMounted, ref } from 'vue';
import { useNotificationStore } from '@/stores/notificationStore';

const store = useNotificationStore();

const DISMISS_MS = 5000;

const timers = ref<Map<string, ReturnType<typeof setTimeout>>>(new Map());

function scheduleRemove(id: string): void {
  const t = setTimeout(() => store.removeToast(id), DISMISS_MS);
  timers.value.set(id, t);
}

function cancelRemove(id: string): void {
  const t = timers.value.get(id);
  if (t !== undefined) clearTimeout(t);
}

function dismiss(id: string): void {
  cancelRemove(id);
  store.removeToast(id);
}

// When a new toast arrives, schedule its removal.
// Using a watcher on toasts array is cleaner, but onMounted covers initial mount.
onMounted(() => {
  store.toasts.forEach((t) => scheduleRemove(t.id));
});

// Expose for the app to call after adding a toast.
defineExpose({ scheduleRemove });
</script>

<template>
  <Teleport to="body">
    <div
      aria-live="polite"
      aria-atomic="false"
      class="fixed bottom-4 right-4 z-50 flex flex-col gap-2"
    >
      <div
        v-for="toast in store.toasts"
        :key="toast.id"
        role="status"
        class="flex min-w-[280px] max-w-sm items-start gap-3 rounded-card border border-border bg-surface p-4 shadow-card"
        @mouseenter="cancelRemove(toast.id)"
        @mouseleave="scheduleRemove(toast.id)"
      >
        <span
          class="mt-0.5 h-4 w-4 shrink-0 rounded-full"
          :class="{
            'bg-success': toast.type === 'success',
            'bg-error': toast.type === 'error',
            'bg-warning': toast.type === 'warning',
            'bg-info': toast.type === 'info',
          }"
          aria-hidden="true"
        />
        <p class="flex-1 text-sm text-text">
          {{ toast.message }}
        </p>
        <button
          type="button"
          :aria-label="`Dismiss: ${toast.message}`"
          class="h-5 w-5 shrink-0 text-text-muted hover:text-text focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary"
          @click="dismiss(toast.id)"
        >
          <svg
            aria-hidden="true"
            viewBox="0 0 20 20"
            fill="currentColor"
          >
            <path d="M4.293 4.293a1 1 0 011.414 0L10 8.586l4.293-4.293a1 1 0 111.414 1.414L11.414 10l4.293 4.293a1 1 0 01-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 01-1.414-1.414L8.586 10 4.293 5.707a1 1 0 010-1.414z" />
          </svg>
        </button>
      </div>
    </div>
  </Teleport>
</template>

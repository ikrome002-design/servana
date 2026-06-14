<script setup lang="ts">
import { onMounted, onUnmounted, ref, watch } from 'vue';

const props = defineProps<{
  open: boolean;
  title: string;
  description?: string;
}>();

const emit = defineEmits<{ close: [] }>();

const dialogRef = ref<HTMLDialogElement | null>(null);

function close(): void {
  emit('close');
}

function onKeydown(e: KeyboardEvent): void {
  if (e.key === 'Escape') close();
}

watch(
  () => props.open,
  (open) => {
    if (open) {
      document.addEventListener('keydown', onKeydown);
      // Focus the dialog on open for screen readers.
      dialogRef.value?.focus();
    } else {
      document.removeEventListener('keydown', onKeydown);
    }
  },
);

onUnmounted(() => {
  document.removeEventListener('keydown', onKeydown);
});

// Prevent body scroll when modal is open.
watch(
  () => props.open,
  (open) => {
    document.body.style.overflow = open ? 'hidden' : '';
  },
);

onMounted(() => {
  if (props.open) document.body.style.overflow = 'hidden';
});
</script>

<template>
  <Teleport to="body">
    <div
      v-if="open"
      class="fixed inset-0 z-50 flex items-center justify-center p-4"
      @click.self="close"
    >
      <!-- Overlay -->
      <div
        class="absolute inset-0 bg-black/50"
        aria-hidden="true"
      />
      <!-- Dialog -->
      <div
        ref="dialogRef"
        role="dialog"
        aria-modal="true"
        :aria-labelledby="'modal-title'"
        :aria-describedby="description ? 'modal-desc' : undefined"
        tabindex="-1"
        class="relative z-10 w-full max-w-lg rounded-card bg-surface p-6 shadow-xl focus:outline-none"
      >
        <h2
          id="modal-title"
          class="font-display text-lg font-bold text-brand-deep"
        >
          {{ title }}
        </h2>
        <p
          v-if="description"
          id="modal-desc"
          class="mt-1 text-sm text-text-muted"
        >
          {{ description }}
        </p>
        <div class="mt-4">
          <slot />
        </div>
        <button
          type="button"
          aria-label="Close modal"
          class="absolute right-4 top-4 flex h-8 w-8 items-center justify-center rounded-control text-text-muted hover:bg-surface-alt focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary"
          @click="close"
        >
          <svg
            aria-hidden="true"
            class="h-4 w-4"
            fill="none"
            viewBox="0 0 24 24"
            stroke="currentColor"
          >
            <path
              stroke-linecap="round"
              stroke-linejoin="round"
              stroke-width="2"
              d="M6 18L18 6M6 6l12 12"
            />
          </svg>
        </button>
      </div>
    </div>
  </Teleport>
</template>

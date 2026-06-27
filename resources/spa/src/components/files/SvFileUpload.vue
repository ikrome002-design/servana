<script setup lang="ts">
/**
 * Reusable, accessible file-upload control (Plan §65; Phase 10F).
 *
 * Foundation only — no feature-specific business screen. Drives the upload state
 * machine (selecting → uploading → scanning → available | rejected | error) and
 * announces changes via an aria-live region. The transport is injected so the
 * component stays testable and feature-agnostic; nothing (signed URL or storage
 * metadata) is ever written to localStorage.
 */
import { computed, ref } from 'vue';

export type UploadState = 'selecting' | 'uploading' | 'scanning' | 'available' | 'rejected' | 'error';

export interface UploadedFileResource {
  id: string;
  purpose: string;
  scan_status: 'pending' | 'clean' | 'infected' | 'scan_failed' | 'rejected';
  lifecycle_status: 'quarantined' | 'available' | 'revoked' | 'expired' | 'deleted';
  safe_download_filename: string;
  size_bytes: number;
  can: { download: boolean };
}

/** Inject the transport: a function that POSTs the file and resolves the resource. */
export type FileUploader = (file: File, purpose: string) => Promise<UploadedFileResource>;

const props = withDefaults(
  defineProps<{
    purpose: string;
    label?: string;
    accept?: string;
    maxBytes?: number;
    uploader: FileUploader;
  }>(),
  {
    label: 'Upload a file',
    accept: 'image/png,image/jpeg,image/webp',
    maxBytes: 5 * 1024 * 1024,
  },
);

const emit = defineEmits<{
  (e: 'uploaded', file: UploadedFileResource): void;
  (e: 'rejected', message: string): void;
}>();

const state = ref<UploadState>('selecting');
const message = ref('');
const fileName = ref('');
const inputRef = ref<HTMLInputElement | null>(null);

const statusText = computed(() => {
  switch (state.value) {
    case 'uploading':
      return `Uploading ${fileName.value}…`;
    case 'scanning':
      return `Scanning ${fileName.value} for viruses…`;
    case 'available':
      return `${fileName.value} is ready.`;
    case 'rejected':
      return message.value || 'File rejected.';
    case 'error':
      return message.value || 'Upload failed.';
    default:
      return 'Choose a file to upload.';
  }
});

const sizeHint = computed(() => `Allowed: ${props.accept}. Max ${Math.round(props.maxBytes / 1024 / 1024)} MB.`);

async function onSelect(event: Event): Promise<void> {
  const input = event.target as HTMLInputElement;
  const file = input.files?.[0];
  if (!file) return;

  fileName.value = file.name;

  // Client-side guidance only — the server re-validates (magic-byte MIME, scan).
  if (file.size > props.maxBytes) {
    state.value = 'rejected';
    message.value = `That file is larger than ${Math.round(props.maxBytes / 1024 / 1024)} MB.`;
    emit('rejected', message.value);
    return;
  }

  state.value = 'uploading';
  try {
    const resource = await props.uploader(file, props.purpose);
    if (resource.scan_status === 'pending' || resource.lifecycle_status === 'quarantined') {
      state.value = 'scanning';
    } else if (resource.lifecycle_status === 'available') {
      state.value = 'available';
    }
    emit('uploaded', resource);
  } catch (e) {
    const err = e as { response?: { data?: { error?: { message?: string } } } };
    state.value = 'error';
    message.value = err.response?.data?.error?.message ?? 'Upload failed. Please try again.';
    emit('rejected', message.value);
  }
}

function reset(): void {
  state.value = 'selecting';
  message.value = '';
  fileName.value = '';
  if (inputRef.value) inputRef.value.value = '';
}

defineExpose({ state, reset });
</script>

<template>
  <div class="sv-file-upload">
    <label :for="`file-${purpose}`" class="block text-sm font-medium text-gray-900 dark:text-gray-100">
      {{ label }}
    </label>
    <p :id="`file-hint-${purpose}`" class="mt-1 text-xs text-gray-600 dark:text-gray-400">{{ sizeHint }}</p>

    <input
      :id="`file-${purpose}`"
      ref="inputRef"
      type="file"
      :accept="accept"
      :aria-describedby="`file-hint-${purpose} file-status-${purpose}`"
      :disabled="state === 'uploading' || state === 'scanning'"
      class="mt-2 block w-full min-h-[44px] rounded-md border border-gray-300 bg-white px-3 py-2 text-sm text-gray-900 file:mr-3 file:min-h-[44px] file:rounded file:border-0 file:bg-gray-100 file:px-3 file:py-2 focus:outline-none focus:ring-2 focus:ring-savannah-orange dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100 dark:file:bg-gray-700"
      @change="onSelect"
    />

    <!-- Live status announced to assistive tech as the state machine advances. -->
    <p
      :id="`file-status-${purpose}`"
      role="status"
      aria-live="polite"
      class="mt-2 min-h-[44px] flex items-center text-sm"
      :class="{
        'text-gray-700 dark:text-gray-300': state === 'selecting' || state === 'uploading' || state === 'scanning',
        'text-green-700 dark:text-green-400': state === 'available',
        'text-red-700 dark:text-red-400': state === 'rejected' || state === 'error',
      }"
    >
      <span v-if="state === 'uploading' || state === 'scanning'" class="mr-2 animate-pulse" aria-hidden="true">●</span>
      {{ statusText }}
    </p>

    <button
      v-if="state === 'rejected' || state === 'error'"
      type="button"
      class="mt-2 min-h-[44px] rounded-md bg-savannah-orange px-4 py-2 text-sm font-medium text-brand-deep focus:outline-none focus:ring-2 focus:ring-offset-2"
      @click="reset"
    >
      Try again
    </button>
  </div>
</template>

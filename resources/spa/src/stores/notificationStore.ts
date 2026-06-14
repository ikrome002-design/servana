import { defineStore } from 'pinia';
import { ref } from 'vue';

export interface Toast {
  id: string;
  message: string;
  type: 'success' | 'error' | 'warning' | 'info';
}

export const useNotificationStore = defineStore('notification', () => {
  const toasts = ref<Toast[]>([]);

  function addToast(toast: Omit<Toast, 'id'>): string {
    const id = crypto.randomUUID();
    toasts.value.push({ ...toast, id });
    return id;
  }

  function removeToast(id: string): void {
    toasts.value = toasts.value.filter((t) => t.id !== id);
  }

  return { toasts, addToast, removeToast };
});

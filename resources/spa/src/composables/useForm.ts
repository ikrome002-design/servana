import { computed, reactive, ref } from 'vue';
import type { ApiError } from '@/types/api';

export function useForm<T extends Record<string, unknown>>(initialValues: T) {
  const original = JSON.parse(JSON.stringify(initialValues)) as T;
  // eslint-disable-next-line @typescript-eslint/no-explicit-any
  const values = reactive({ ...initialValues }) as any;
  const errors = reactive<Record<string, string[]>>({});
  const touched = reactive<Record<string, boolean>>({});
  const submitting = ref(false);

  const dirty = computed(() =>
    (Object.keys(original) as Array<keyof T>).some(
      (k) => values[k] !== original[k],
    ),
  );

  function reset(): void {
    (Object.keys(original) as Array<keyof T>).forEach((k) => {
      values[k as string] = original[k];
      delete errors[k as string];
      delete touched[k as string];
    });
    submitting.value = false;
  }

  function setFieldError(field: keyof T, messages: string[]): void {
    errors[field as string] = messages;
  }

  function mergeServerErrors(apiError: ApiError): void {
    Object.entries(apiError.fields).forEach(([field, messages]) => {
      errors[field] = messages;
    });
  }

  function touch(field: keyof T): void {
    touched[field as string] = true;
  }

  function handleSubmit(fn: (values: T) => Promise<void>) {
    return async (): Promise<void> => {
      if (submitting.value) return;
      submitting.value = true;
      try {
        await fn(values as T);
      } finally {
        submitting.value = false;
      }
    };
  }

  return {
    values: values as T,
    errors,
    touched,
    submitting,
    dirty,
    reset,
    setFieldError,
    mergeServerErrors,
    handleSubmit,
    touch,
  };
}

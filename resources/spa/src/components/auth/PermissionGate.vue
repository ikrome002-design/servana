<script setup lang="ts">
import { computed } from 'vue';
import { useCan } from '@/composables/useCan';

/**
 * Conditionally render a slot based on the user's resolved permissions
 * (Plan §10.3). UX ONLY — hiding an action is a convenience, never a security
 * control; every mutating route is enforced server-side by EnsurePermission +
 * policies.
 *
 * Pass a single key via `permission`, or several via `any` / `all`. When the
 * check fails, the optional `#denied` slot renders instead (e.g. a no-access
 * note); otherwise nothing renders.
 */
const props = withDefaults(
  defineProps<{
    permission?: string;
    any?: string[];
    all?: string[];
  }>(),
  { permission: undefined, any: () => [], all: () => [] },
);

const { can, canAny, canAll } = useCan();

const allowed = computed<boolean>(() => {
  if (props.permission !== undefined && !can(props.permission)) {
    return false;
  }
  if (props.any.length > 0 && !canAny(props.any)) {
    return false;
  }
  if (props.all.length > 0 && !canAll(props.all)) {
    return false;
  }
  return true;
});
</script>

<template>
  <slot v-if="allowed" />
  <slot
    v-else
    name="denied"
  />
</template>

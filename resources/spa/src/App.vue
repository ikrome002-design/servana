<script setup lang="ts">
import { onMounted } from 'vue';
import SvToast from '@/components/ui/SvToast.vue';
import { useAuthStore } from '@/stores/authStore';

// The router's global beforeEach resolves the session before the first route
// renders (Plan §6.2). This is a defensive fallback for the rare case where no
// navigation occurs; bootstrap() is idempotent via the `bootstrapped` flag.
const auth = useAuthStore();
onMounted(() => {
  if (!auth.bootstrapped) void auth.bootstrap();
});
</script>

<template>
  <RouterView />
  <SvToast />
</template>

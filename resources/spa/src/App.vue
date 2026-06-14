<script setup lang="ts">
import { onMounted } from 'vue';
import SvToast from '@/components/ui/SvToast.vue';
import { useAuthStore } from '@/stores/authStore';

// Resolve any existing session on first paint (Plan §6.2). 401 is expected when
// logged out; the store handles it and marks itself bootstrapped either way.
const auth = useAuthStore();
onMounted(() => {
  void auth.bootstrap();
});
</script>

<template>
  <RouterView />
  <SvToast />
</template>

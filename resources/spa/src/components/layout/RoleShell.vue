<script setup lang="ts">
import { computed } from 'vue';
import AppShell from '@/components/layout/AppShell.vue';
import SvStateBoundary from '@/components/ui/SvStateBoundary.vue';
import { activeRoleIdentity } from '@/router/destinations';

/**
 * Shared authenticated role layout body (Phase 11). Resolves the active role
 * identity from the bootstrap (UX only) and renders the AppShell with the
 * correct navigation placement. Each of the eight role layout shells delegates
 * here, so the placement rule and chrome live in one audited place. An
 * unresolved/unsupported role fails safe to an accessible boundary.
 */
const identity = computed(() => activeRoleIdentity());
</script>

<template>
  <AppShell
    v-if="identity"
    :identity="identity"
  >
    <RouterView />
  </AppShell>
  <main
    v-else
    id="main-content"
    class="mx-auto max-w-xl p-6"
  >
    <SvStateBoundary
      state="error"
      error-message="Your account role isn't supported here. Please contact your administrator."
    />
  </main>
</template>

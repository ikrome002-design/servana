<script setup lang="ts">
import { computed } from 'vue';
import { useRoute } from 'vue-router';
import RoleLandingScaffold from '@/components/layout/RoleLandingScaffold.vue';
import SvStateBoundary from '@/components/ui/SvStateBoundary.vue';
import { ROLE_IDENTITIES, type RoleIdentity } from '@/types/roles';

/**
 * Role landing page (Plan §27.2; Scope §3.1). One component, role-specific
 * content: the active role identity comes from the route meta of the role-area
 * route the user landed on, so each role renders its own verbatim copy, imagery,
 * actions, FAQ, and legal footer. An unknown identity fails safe to an
 * unsupported-role boundary rather than leaking another role's surface.
 */
const route = useRoute();
const identity = computed<RoleIdentity | null>(() => {
  const meta = route.meta.roleIdentity;
  return typeof meta === 'string' && ROLE_IDENTITIES.includes(meta as RoleIdentity)
    ? (meta as RoleIdentity)
    : null;
});
</script>

<template>
  <RoleLandingScaffold
    v-if="identity"
    :identity="identity"
  />
  <SvStateBoundary
    v-else
    state="error"
    error-message="This account role isn't supported here. Please contact your administrator."
  />
</template>

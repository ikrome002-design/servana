<script setup lang="ts">
// Branch Manager shell (Phase 11). Sidebar/drawer primary navigation; the active role identity
// (resolved from the bootstrap) selects the correct nav.
//
// Phase UI-04 (UI01-NAV-002): this layout served Human Resource as well, which made HR the one
// account presenting under another account's identity. HR now has HumanResourceLayout.
import RoleShell from '@/components/layout/RoleShell.vue';
</script>

<template>
  <RoleShell />
</template>

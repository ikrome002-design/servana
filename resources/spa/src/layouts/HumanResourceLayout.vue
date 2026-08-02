<script setup lang="ts">
/**
 * Human Resource shell (Phase UI-04 — closes UI01-NAV-002).
 *
 * The audited defect: `ROLE_ENTRY` mapped BOTH `merchant_branch` and `merchant_human_resource` to
 * `BranchLayout`, and `router/routes/hr.ts` imported that layout directly. Every other account had
 * a layout of its own, so HR was the one account presenting under another account's identity.
 *
 * The correction is COMPOSITIONAL, not a copy. Like the seven sibling layouts, this delegates to
 * the shared `RoleShell`, which resolves the active account identity from the server bootstrap and
 * hands `AppShell` the right navigation, label and chrome. Duplicating `BranchLayout`'s markup
 * would have created a second shell to keep in step — the opposite of what a shared design system
 * is for.
 *
 * What changes for HR is metadata, not structure: `ROLE_ENTRY.merchant_human_resource` now names
 * this layout and the label "Human Resource" rather than "HR". Backend authority is untouched —
 * HR remains branch-scoped, and every mutation is still re-authorized server-side. No
 * Branch-owned command is exposed by the shell, because the shell renders HR's own navigation.
 *
 * The nineteen final HR pages belong to UI-11. This phase corrects the shell identity only.
 */
import RoleShell from '@/components/layout/RoleShell.vue';
</script>

<template>
  <RoleShell />
</template>

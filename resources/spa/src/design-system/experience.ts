import { computed, inject, type ComputedRef, type InjectionKey, type Ref } from 'vue';
import type { RoleIdentity } from '@/types/roles';

/**
 * Visual experience scope (COR-UI08-UI12-VIS-001).
 *
 * The product owner rejected the visual result of the five oldest authenticated experiences
 * (UI-08 … UI-12) and authorised a reference-grade redesign of exactly those accounts. The shell,
 * navigation and many primitives are SHARED with Front Office, Personnel and Audit, whose UI-13 …
 * UI-15 presentation was not part of that decision. A global restyle would therefore redesign three
 * accounts nobody asked to change.
 *
 * So the redesign is SCOPED: the shell marks the document with `data-sv-experience="refined"` for
 * the five corrective accounts only, and every refinement — token overrides in
 * `styles/refined-experience.css` and the few template branches that read `EXPERIENCE_KEY` — keys
 * off that one decision. The other three accounts, the public landing pages and the auth flow keep
 * the `standard` experience byte for byte.
 *
 * Presentation only. The experience never decides what a user may see or do: navigation is still
 * filtered by `navigationTree()` and every action is still authorised by the server (ADR-017).
 */
export type Experience = 'standard' | 'refined';

export const REFINED_EXPERIENCE_ACCOUNTS: ReadonlySet<RoleIdentity> = new Set<RoleIdentity>([
  'super_administrator',
  'merchant_administrator',
  'merchant_branch',
  'merchant_human_resource',
  'merchant_finance',
]);

export function experienceFor(identity: RoleIdentity): Experience {
  return REFINED_EXPERIENCE_ACCOUNTS.has(identity) ? 'refined' : 'standard';
}

/** The document attribute the refined stylesheet keys off. */
export const EXPERIENCE_ATTRIBUTE = 'data-sv-experience';

export const EXPERIENCE_KEY: InjectionKey<Ref<Experience>> = Symbol('sv-experience');

/**
 * Whether the component renders inside the refined experience. Shared pages (Get Started, the
 * checklist) use it to choose their refined template; outside an AppShell — a unit test, a public
 * page — it is `false`, so the standard template is the default everywhere nothing opts in.
 */
export function useRefinedExperience(): ComputedRef<boolean> {
  const experience = inject(EXPERIENCE_KEY, null);
  return computed(() => experience?.value === 'refined');
}

/**
 * Apply the experience to the document root. Teleported dialogs, drawers and popovers render under
 * `<body>`, outside the shell element, so the scope has to live on `<html>` for them to inherit it.
 */
export function applyExperience(experience: Experience | null): void {
  if (typeof document === 'undefined') return;
  const root = document.documentElement;
  if (experience === null || experience === 'standard') root.removeAttribute(EXPERIENCE_ATTRIBUTE);
  else root.setAttribute(EXPERIENCE_ATTRIBUTE, experience);
}

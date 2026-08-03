/**
 * Canonical public route helpers (Phase UI-06; UI/UX plan §4.2, §17.1).
 *
 * The public legal and FAQ routes are HOST-DERIVED: the account comes from the server-resolved
 * account context, never from a path segment. These helpers exist so no component builds those
 * destinations by hand — a hand-built `{ name, params }` is how a role parameter creeps back in.
 *
 * They deliberately live outside `router/index.ts` so a shared component can import a destination
 * without importing the whole route table.
 */
import type { RouteLocationNamedRaw } from 'vue-router';

/** The three legal documents every account host publishes, at role-free paths. */
export const PUBLIC_LEGAL_DOCS = ['data-policy', 'privacy-policy', 'terms-of-service'] as const;

export type PublicLegalDoc = (typeof PUBLIC_LEGAL_DOCS)[number];

export const PUBLIC_LEGAL_TITLES: Record<PublicLegalDoc, string> = {
  'data-policy': 'Data Policy',
  'privacy-policy': 'Privacy Policy',
  'terms-of-service': 'Terms of Service',
};

/** True for one of the three canonical document slugs. */
export function isPublicLegalDoc(value: unknown): value is PublicLegalDoc {
  return typeof value === 'string' && (PUBLIC_LEGAL_DOCS as readonly string[]).includes(value);
}

/** `/legal/<doc>` on the current host. The account is resolved server-side, never from the path. */
export function publicLegalLocation(doc: PublicLegalDoc): RouteLocationNamedRaw {
  return { name: 'public.legal', params: { doc } };
}

/** `/faq` on the current host. */
export function publicFaqLocation(): RouteLocationNamedRaw {
  return { name: 'public.faq' };
}

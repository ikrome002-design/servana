import type { RouteLocationRaw } from 'vue-router';

/**
 * The canonical merchant-detail destination (Phase UI-08; contract page §5.4.12).
 *
 * Declared once so the registration feed, the directory and the breadcrumbs cannot drift onto three
 * spellings of the same route. The route itself is REGISTERED IN INCREMENT 7B — activation of all
 * seventeen Super Administrator destinations is one atomic step, and these pages are built first so
 * that 7B activates finished screens rather than the other way round.
 */
export const MERCHANT_DETAIL_ROUTE_NAME = 'platform.merchant-detail';
export const MERCHANT_DIRECTORY_ROUTE_NAME = 'platform.merchants';
export const MERCHANT_REGISTRATIONS_ROUTE_NAME = 'platform.merchant-registrations';

/**
 * The platform audit destination. Named here, beside the merchant routes, because the merchant
 * detail page links to it: platform governance events are searchable there, and this page cannot
 * scope the audit read to one merchant (the shipped read accepts no merchant filter), so it links
 * to the audit surface rather than rendering a partial timeline as if it were complete.
 */
export const PLATFORM_AUDIT_ROUTE_NAME = 'platform.audit';

/** A location, never a hand-built URL string: the router owns path construction and encoding. */
export function merchantDetailLocation(merchantUlid: string): RouteLocationRaw {
  return { name: MERCHANT_DETAIL_ROUTE_NAME, params: { merchantUlid } };
}

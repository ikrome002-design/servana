import type { RouteRecordRaw } from 'vue-router';
import { currentAccountContext } from '@/host/accountHostContext';
import { isPublicLegalDoc, PUBLIC_LEGAL_DOCS } from '@/router/publicRoutes';

/**
 * Public routes present on every approved account host (Phase UI-06; UI/UX plan §4.2, §17.1).
 *
 * ```text
 * /                        the account's landing page
 * /faq                     the account's FAQ
 * /legal/data-policy       the account's Data Policy
 * /legal/privacy-policy    the account's Privacy Policy
 * /legal/terms-of-service  the account's Terms of Service
 * ```
 *
 * The account is HOST-DERIVED in every case. `/legal/data-policy` on the Finance host renders the
 * Finance Data Policy because the server resolved the Finance account, not because a path segment
 * said so — which is what makes "no path input may select an arbitrary role content module" true
 * rather than merely intended.
 *
 * ## The role-parameter compatibility route
 *
 * `/legal/:role/:doc` predates this phase (Phase 11) and is what the footer and the authenticated
 * shell linked. UI-06 migrates every internal link to the canonical paths above and keeps the old
 * shape as a REDIRECT ONLY, under two rules:
 *
 *  - a role EQUAL to the resolved host account redirects to the canonical, role-free path;
 *  - any other role fails closed in `LegalDocument.vue` — it never renders, and it never redirects
 *    to another host, because a redirect that crossed accounts would be a worse defect than the one
 *    being fixed.
 *
 * When no account context is resolved at all — the standalone Vite preview origin has no Laravel
 * shell to embed one — there is no "resolved host account" for the rule to compare against, so the
 * route behaves exactly as it did before this phase. In production the shell always embeds a
 * context, so the restriction always applies. Recorded as UI06-LEGAL-001.
 */
export const publicRoutes: RouteRecordRaw[] = [
  {
    path: '/',
    name: 'home',
    component: () => import('@/pages/public/PublicLandingPage.vue'),
  },
  {
    path: '/faq',
    name: 'public.faq',
    component: () => import('@/pages/public/PublicFaqPage.vue'),
  },
  {
    // A closed alternation, so an unknown document is a routing miss rather than a component
    // branch — the not-found boundary answers it, not the legal renderer.
    path: `/legal/:doc(${PUBLIC_LEGAL_DOCS.join('|')})`,
    name: 'public.legal',
    component: () => import('@/pages/public/PublicLegalPage.vue'),
  },
  {
    path: '/legal/:role/:doc',
    name: 'legal.document',
    component: () => import('@/pages/legal/LegalDocument.vue'),
    beforeEnter: (to) => {
      const context = currentAccountContext();

      // No resolved context: nothing to compare against, so behaviour is unchanged.
      if (context === null) {
        return true;
      }

      // The account's own documents, reached by the legacy shape: send the browser to the
      // canonical path so only one URL publishes them. An unrecognised document slug is NOT
      // redirected — `public.legal` accepts only the three canonical slugs, so redirecting one
      // would fail to resolve; it falls through to the not-found boundary instead.
      if (to.params['role'] === context.legalContentKey && isPublicLegalDoc(to.params['doc'])) {
        return { name: 'public.legal', params: { doc: to.params['doc'] }, replace: true };
      }

      // A different role: LegalDocument renders its not-found boundary. Deliberately NOT a
      // redirect — the only safe answer is to show nothing.
      return true;
    },
  },
];

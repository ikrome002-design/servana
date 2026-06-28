import type { RoleIdentity } from '@/types/roles';
import { parseFaq, parseHero, type FaqItem, type HeroContent } from './markdown';

// Approved role content imported verbatim from the version-controlled docs
// (Plan §27.2; Phase 11). `?raw` keeps a single source of truth — legal/FAQ/
// landing text is never hand-copied into frontend source and cannot paraphrase.

// Landing-page copy.
import superAdminLanding from '@docs/landing_page/super_administrator_landing_page_content.md?raw';
import merchantAdminLanding from '@docs/landing_page/merchant_administrator_landing_page_content.md?raw';
import branchLanding from '@docs/landing_page/merchant_branch_landing_page_content.md?raw';
import hrLanding from '@docs/landing_page/merchant_human_resource_landing_page_content.md?raw';
import financeLanding from '@docs/landing_page/merchant_finance_landing_page_content.md?raw';
import frontOfficeLanding from '@docs/landing_page/merchant_front_office_landing_page_content.md?raw';
import personnelLanding from '@docs/landing_page/merchant_personnel_landing_page_content.md?raw';
import auditLanding from '@docs/landing_page/merchant_audit_landing_page_content.md?raw';

// FAQ help documents.
import superAdminFaq from '@docs/support/faq/super_administrator_faq.md?raw';
import merchantAdminFaq from '@docs/support/faq/merchant_administrator_faq.md?raw';
import branchFaq from '@docs/support/faq/merchant_branch_faq.md?raw';
import hrFaq from '@docs/support/faq/merchant_human_resource_faq.md?raw';
import financeFaq from '@docs/support/faq/merchant_finance_faq.md?raw';
import frontOfficeFaq from '@docs/support/faq/merchant_front_office_faq.md?raw';
import personnelFaq from '@docs/support/faq/merchant_personnel_faq.md?raw';
import auditFaq from '@docs/support/faq/merchant_audit_faq.md?raw';

// Legal documents (~3 MB total) are loaded lazily per document via
// content/legalContent.ts so a landing view never pulls every role's legal text.
export type LegalDocType = 'terms-of-service' | 'privacy-policy' | 'data-policy';

export interface LegalDocMeta {
  type: LegalDocType;
  title: string;
}

/** The three legal documents shown in every footer + final acknowledgement. */
export const LEGAL_DOCS: LegalDocMeta[] = [
  { type: 'terms-of-service', title: 'Terms of Service' },
  { type: 'privacy-policy', title: 'Privacy Policy' },
  { type: 'data-policy', title: 'Data Policy' },
];

interface RoleContentSources {
  landing: string;
  faq: string;
  /** Number of approved images in public/assets/landing_page_images/{identity}/. */
  imageCount: number;
}

const SOURCES: Record<RoleIdentity, RoleContentSources> = {
  super_administrator: { landing: superAdminLanding, faq: superAdminFaq, imageCount: 10 },
  merchant_administrator: { landing: merchantAdminLanding, faq: merchantAdminFaq, imageCount: 8 },
  merchant_branch: { landing: branchLanding, faq: branchFaq, imageCount: 9 },
  merchant_human_resource: { landing: hrLanding, faq: hrFaq, imageCount: 8 },
  merchant_finance: { landing: financeLanding, faq: financeFaq, imageCount: 5 },
  merchant_front_office: { landing: frontOfficeLanding, faq: frontOfficeFaq, imageCount: 6 },
  merchant_personnel: { landing: personnelLanding, faq: personnelFaq, imageCount: 7 },
  merchant_audit: { landing: auditLanding, faq: auditFaq, imageCount: 8 },
};

const heroCache = new Map<RoleIdentity, HeroContent>();
const faqCache = new Map<RoleIdentity, FaqItem[]>();

/** Verbatim hero (headline + body) parsed from the role's landing doc. */
export function getLandingHero(identity: RoleIdentity): HeroContent {
  let hero = heroCache.get(identity);
  if (!hero) {
    hero = parseHero(SOURCES[identity].landing);
    heroCache.set(identity, hero);
  }
  return hero;
}

/** Verbatim FAQ items parsed from the role's FAQ doc. */
export function getFaq(identity: RoleIdentity): FaqItem[] {
  let items = faqCache.get(identity);
  if (!items) {
    items = parseFaq(SOURCES[identity].faq);
    faqCache.set(identity, items);
  }
  return items;
}

/** Approved landing-image src list for a role (served by Nginx from public/). */
export function landingImages(identity: RoleIdentity): string[] {
  const count = SOURCES[identity].imageCount;
  return Array.from(
    { length: count },
    (_v, i) => `/assets/landing_page_images/${identity}/${i + 1}.png`,
  );
}

/** Primary hero image for a role (the first approved image). */
export function heroImage(identity: RoleIdentity): string {
  return `/assets/landing_page_images/${identity}/1.png`;
}

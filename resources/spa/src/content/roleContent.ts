import type { RoleIdentity } from '@/types/roles';

// Approved role content lives verbatim in the version-controlled docs (Plan §27.2;
// Phase 11) and is never hand-copied into frontend source.
//
// Phase 24 (PH24-BUNDLE-001): the eight landing and eight FAQ documents used to be
// STATIC `?raw` imports in this module, so every importer — including two that only
// wanted `LEGAL_DOCS` — bundled all eight roles' copy. They now load lazily, one
// document per role, from `content/roleDocuments.ts`, exactly as the legal documents
// already do via `content/legalContent.ts`. This module is deliberately free of
// markdown imports so that importing a constant costs no content.

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

/** Number of approved images in public/assets/landing_page_images/{identity}/. */
const IMAGE_COUNTS: Record<RoleIdentity, number> = {
  super_administrator: 10,
  merchant_administrator: 8,
  merchant_branch: 9,
  merchant_human_resource: 8,
  merchant_finance: 5,
  merchant_front_office: 6,
  merchant_personnel: 7,
  merchant_audit: 8,
};

/** Approved landing-image src list for a role (served by Nginx from public/). */
export function landingImages(identity: RoleIdentity): string[] {
  const count = IMAGE_COUNTS[identity];
  return Array.from(
    { length: count },
    (_v, i) => `/assets/landing_page_images/${identity}/${i + 1}.png`,
  );
}

/** Primary hero image for a role (the first approved image). */
export function heroImage(identity: RoleIdentity): string {
  return `/assets/landing_page_images/${identity}/1.png`;
}

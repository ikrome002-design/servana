import type { RoleIdentity } from '@/types/roles';
import { landingHeroImage, landingImagesFor } from '@/content/generated/landingImages.generated';

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

/**
 * Curated landing imagery for a role.
 *
 * Phase 11 enumerated EVERY supplied file (`1.png` … `N.png`) from a hard-coded count. UI/UX plan
 * §8.7 forbids rendering every image merely because it exists, and a hard-coded count silently
 * lies the moment a directory changes. Phase UI-05 replaced both with the curated manifest: two to
 * four images per role, each mapped to a real landing region, each with measured dimensions,
 * alternative text and responsive AVIF/WebP candidates, and each verified to come from that role's
 * own directory.
 */
export function landingImages(identity: RoleIdentity): string[] {
  return landingImagesFor(identity).map((image) => image.sourcePublicPath);
}

/** The role's curated hero image, or null when its manifest declares none. */
export function heroImage(identity: RoleIdentity): string | null {
  return landingHeroImage(identity)?.sourcePublicPath ?? null;
}

import type { LegalDocType } from '@/content/roleContent';
import type { RoleIdentity } from '@/types/roles';

/**
 * Lazily-loaded legal documents (Phase 11). The approved legal text (~3 MB
 * across all roles) is sourced verbatim from `docs/legal/**` but loaded one
 * document at a time via `import.meta.glob`, so viewing a single legal page
 * fetches only that one document rather than bundling every role's legal text
 * into the landing chunk. Single source of truth; never hand-copied into source.
 */
const loaders = import.meta.glob<string>('../../../../docs/legal/**/*.md', {
  query: '?raw',
  import: 'default',
});

/** docs/legal/{folder}/{identity}_{folder}.md for a given role + doc type. */
function pathSuffix(identity: RoleIdentity, type: LegalDocType): string {
  const folder = type.replace(/-/g, '_');
  return `/legal/${folder}/${identity}_${folder}.md`;
}

/** Resolve and load the verbatim markdown for a role's legal document. */
export async function loadLegalDoc(
  identity: RoleIdentity,
  type: LegalDocType,
): Promise<string> {
  const suffix = pathSuffix(identity, type);
  const key = Object.keys(loaders).find((k) => k.endsWith(suffix));
  if (!key) {
    throw new Error(`Legal document not found: ${identity}/${type}`);
  }
  return loaders[key]();
}

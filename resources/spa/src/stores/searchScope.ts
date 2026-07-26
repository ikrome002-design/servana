import type { components } from '@/types/generated/api';

/** One search result, typed from the GENERATED contract — never hand-written. */
export type SearchResult = components['schemas']['SearchResultResource'];

/**
 * The complete set of parameters the SPA may send to `GET /api/v1/search`
 * (`docs/architecture/search/search-security.md` §2).
 *
 * This type is the frontend half of the request allowlist. It has no
 * `merchant_id`, `branch_id`, `staff_profile_id`, `permission`, `role`, `filter`,
 * `index`, `api_key`, `include_*`, `export`, `download`, `print` or `copy` — the
 * server rejects all of them with 422, and the store has no way to construct one.
 *
 * `branch_ulids` is the one scope-shaped parameter the API accepts, and it can only
 * NARROW: the server intersects it with the membership's own reachable branches.
 */
export interface SearchParams {
  q: string;
  types?: SearchDocumentType[];
  branch_ulids?: string[];
  sort?: SearchSortToken;
  limit?: number;
}

/** Mirrors `App\Domain\Search\Enums\SearchDocumentType`. */
export type SearchDocumentType =
  | 'client'
  | 'staff'
  | 'appointment'
  | 'queue_entry'
  | 'service_session'
  | 'invoice'
  | 'receipt'
  | 'served_client';

/** Mirrors `App\Domain\Search\Enums\SearchSort`. */
export type SearchSortToken = 'relevance' | 'recent';

/**
 * How the search surface failed, when it did. Distinguished because the copy differs
 * materially: a rate limit is "slow down", a forbidden is "your session changed",
 * and an error is "try again".
 */
export type SearchFailure = 'none' | 'error' | 'forbidden' | 'rate_limited';

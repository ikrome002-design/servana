// Typed error codes from the Phase 3 structured error envelope (Plan §11.5).
export type ApiErrorCode =
  | 'unauthenticated'
  | 'permission_denied'
  | 'merchant_suspended'
  | 'period_locked'
  | 'duplicate_reference'
  | 'duplicate_client'
  | 'rate_limited'
  | 'internal_error'
  | 'invalid_state_transition'
  | 'validation_error'
  | string;

export interface ApiError {
  code: ApiErrorCode;
  message: string;
  fields: Record<string, string[]>;
  meta: Record<string, unknown>;
}

export interface ApiErrorEnvelope {
  error: ApiError;
}

export interface Paginated<T> {
  data: T[];
  meta: {
    current_page: number;
    per_page: number;
    total: number;
    last_page: number;
  };
  links: {
    first: string | null;
    last: string | null;
    prev: string | null;
    next: string | null;
  };
}

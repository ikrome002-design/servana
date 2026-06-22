import axios, { type AxiosInstance, type AxiosResponse } from 'axios';
import type { ApiError, ApiErrorEnvelope } from '@/types/api';

// The response interceptor attaches a typed, parsed ApiError to rejected
// AxiosErrors so callers can discriminate outcomes without re-parsing.
declare module 'axios' {
  interface AxiosError {
    apiError?: ApiError;
  }
}

// Single axios instance (Plan §6.3). Backend CORS is strict-origin; credentials
// are required for Sanctum SPA cookie sessions.
export const apiClient: AxiosInstance = axios.create({
  baseURL: '/api/v1',
  withCredentials: true,
  headers: {
    'X-Requested-With': 'XMLHttpRequest',
    Accept: 'application/json',
  },
});

// Prime the CSRF cookie before any mutating call. Idempotent — a cookie that
// already exists is simply refreshed. Phase 5 (auth) will call this before
// Magic Link requests and login.
export async function primeCsrfCookie(): Promise<void> {
  await axios.get('/sanctum/csrf-cookie', { withCredentials: true });
}

// Central handler for an authentication-revocation 401 (Plan §79 R6). The app
// registers a callback (clear auth state + redirect to login) at startup. The
// backend is the security boundary — this only keeps the UI honest after the
// server has already revoked access mid-session.
type UnauthorizedHandler = () => void;
let unauthorizedHandler: UnauthorizedHandler | null = null;

export function setUnauthorizedHandler(handler: UnauthorizedHandler): void {
  unauthorizedHandler = handler;
}

// Requests whose own callers own the 401/422 outcome — never trigger the global
// redirect for these (the session bootstrap legitimately 401s when logged out,
// and the auth/csrf endpoints drive their own screens). Prevents redirect loops.
export function ownsUnauthenticatedResponse(url: string | undefined): boolean {
  if (url === undefined) {
    return false;
  }
  return url.startsWith('/auth/') || url === '/me' || url.includes('/sanctum/');
}

function isApiErrorEnvelope(data: unknown): data is ApiErrorEnvelope {
  return (
    typeof data === 'object' &&
    data !== null &&
    'error' in data &&
    typeof (data as ApiErrorEnvelope).error === 'object'
  );
}

export function parseApiError(responseData: unknown): ApiError {
  if (isApiErrorEnvelope(responseData)) {
    const e = responseData.error;
    return {
      code: e.code ?? 'internal_error',
      message: e.message ?? 'An unexpected error occurred.',
      fields: e.fields ?? {},
      meta: e.meta ?? {},
    };
  }
  return {
    code: 'internal_error',
    message: 'An unexpected error occurred.',
    fields: {},
    meta: {},
  };
}

// Response interceptor: maps the Phase 3 error envelope to a typed ApiError
// and attaches it as `error.apiError` on the thrown AxiosError for consumers.
apiClient.interceptors.response.use(
  (response: AxiosResponse) => response,
  (err: unknown) => {
    if (
      axios.isAxiosError(err) &&
      err.response !== undefined
    ) {
      // Attach typed error so callers can discriminate without re-parsing.
      err.apiError = parseApiError(err.response.data);

      // A mid-session revocation surfaces as a 401 on a protected call. Clear
      // client state and bounce to login (Plan §79 R6). Bootstrap/auth/csrf
      // 401s are owned by their callers, so they never trigger the redirect.
      if (
        err.response.status === 401 &&
        unauthorizedHandler !== null &&
        !ownsUnauthenticatedResponse(err.config?.url)
      ) {
        unauthorizedHandler();
      }
    }
    return Promise.reject(err);
  },
);

import axios, { type AxiosInstance, type AxiosResponse } from 'axios';
import type { ApiError, ApiErrorEnvelope } from '@/types/api';

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
      const apiError = parseApiError(err.response.data);
      // Attach typed error so callers can discriminate without re-parsing.
      (err as typeof err & { apiError: ApiError }).apiError = apiError;
    }
    return Promise.reject(err);
  },
);

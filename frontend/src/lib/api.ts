/**
 * Fetch wrapper for the Laravel API using Sanctum SPA (cookie) authentication.
 *
 *  - `credentials: 'include'` on every request so the session cookie is sent.
 *  - The XSRF-TOKEN cookie is fetched once and echoed as X-XSRF-TOKEN on every
 *    mutating request (Laravel's double-submit CSRF check).
 *  - Errors become ApiError carrying the server's stable `error_code` and any
 *    field validation errors, so callers never parse message text.
 */

export const API_BASE_URL = import.meta.env.VITE_API_BASE_URL ?? '';

export const SESSION_EXPIRED_EVENT = 'slm:session-expired';

export class ApiError extends Error {
  readonly errorCode: string;
  readonly status: number;
  readonly fieldErrors: Record<string, string[]>;
  readonly payload: Record<string, unknown>;

  constructor(message: string, errorCode: string, status: number, fieldErrors: Record<string, string[]> = {}, payload: Record<string, unknown> = {}) {
    super(message);
    this.name = 'ApiError';
    this.errorCode = errorCode;
    this.status = status;
    this.fieldErrors = fieldErrors;
    this.payload = payload;
  }
}

/*
 * CSRF: the token comes from /api/csrf-token and is sent as X-CSRF-TOKEN.
 * We deliberately don't read Laravel's XSRF-TOKEN cookie: its name is fixed,
 * so another Laravel app on the same parent domain can overwrite it.
 */
let csrfToken: string | null = null;
let csrfRequest: Promise<string | null> | null = null;

function ensureCsrf(force = false): Promise<string | null> {
  if (force) {
    csrfToken = null;
    csrfRequest = null;
  }
  if (csrfToken) return Promise.resolve(csrfToken);

  csrfRequest ??= fetch(`${API_BASE_URL}/api/csrf-token`, {
    credentials: 'include',
    headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
  })
    .then((r) => r.json())
    .then((d: { token: string | null }) => (csrfToken = d.token))
    .finally(() => {
      csrfRequest = null;
    });

  return csrfRequest;
}

/** Call after sign-in or sign-out: the session (and so the token) may have changed. */
export function resetCsrf(): void {
  csrfToken = null;
  csrfRequest = null;
}

type Method = 'GET' | 'POST' | 'PUT' | 'PATCH' | 'DELETE';

export type Query = Record<string, string | number | boolean | null | undefined>;

export function withQuery(path: string, query?: Query): string {
  if (!query) return path;
  const params = new URLSearchParams();
  Object.entries(query).forEach(([k, v]) => {
    if (v !== undefined && v !== null && v !== '') params.set(k, String(v));
  });
  const qs = params.toString();
  return qs ? `${path}${path.includes('?') ? '&' : '?'}${qs}` : path;
}

async function request<T>(method: Method, path: string, body?: unknown, retried = false): Promise<T> {
  const mutating = method !== 'GET';
  const isForm = body instanceof FormData;
  const token = mutating ? await ensureCsrf() : null;

  const headers: Record<string, string> = { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' };
  if (!isForm && body !== undefined) headers['Content-Type'] = 'application/json';
  if (token) headers['X-CSRF-TOKEN'] = token;

  const response = await fetch(`${API_BASE_URL}${path}`, {
    method,
    headers,
    credentials: 'include',
    body: isForm ? (body as FormData) : body !== undefined ? JSON.stringify(body) : undefined,
  });

  // Stale XSRF token: refresh once and replay.
  if (response.status === 419 && mutating && !retried) {
    await ensureCsrf(true);
    return request<T>(method, path, body, true);
  }

  if (response.status === 204) return undefined as T;

  const isJson = (response.headers.get('content-type') ?? '').includes('application/json');
  const payload = isJson ? await response.json() : null;

  if (!response.ok) {
    if (response.status === 401 && path !== '/api/auth/me' && path !== '/api/auth/login') {
      window.dispatchEvent(new CustomEvent(SESSION_EXPIRED_EVENT, { detail: payload?.error_code }));
    }
    throw new ApiError(
      payload?.message ?? `Request failed (${response.status})`,
      payload?.error_code ?? (response.status === 422 ? 'validation' : 'error'),
      response.status,
      payload?.errors ?? {},
      payload ?? {},
    );
  }

  return payload as T;
}

export const api = {
  get: <T>(path: string, query?: Query) => request<T>('GET', withQuery(path, query)),
  post: <T>(path: string, body?: unknown) => request<T>('POST', path, body),
  put: <T>(path: string, body?: unknown) => request<T>('PUT', path, body),
  delete: <T>(path: string) => request<T>('DELETE', path),
};

/** Downloads a file (PDF/CSV) from an authenticated endpoint. */
export async function download(path: string, fallbackName: string): Promise<void> {
  const response = await fetch(`${API_BASE_URL}${path}`, { credentials: 'include', headers: { Accept: '*/*' } });
  if (!response.ok) {
    const payload = await response.json().catch(() => null);
    throw new ApiError(payload?.message ?? 'Download failed', payload?.error_code ?? 'error', response.status);
  }
  const blob = await response.blob();
  const disposition = response.headers.get('content-disposition') ?? '';
  const name = /filename="?([^";]+)"?/.exec(disposition)?.[1] ?? fallbackName;
  const url = URL.createObjectURL(blob);
  const a = document.createElement('a');
  a.href = url;
  a.download = name;
  document.body.appendChild(a);
  a.click();
  a.remove();
  setTimeout(() => URL.revokeObjectURL(url), 1000);
}

export function errorMessage(error: unknown): string {
  if (error instanceof ApiError) {
    const first = Object.values(error.fieldErrors)[0]?.[0];
    return error.errorCode === 'validation' && first ? first : error.message;
  }
  return error instanceof Error ? error.message : 'Something went wrong.';
}

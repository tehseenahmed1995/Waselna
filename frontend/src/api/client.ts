const BASE_URL = import.meta.env.VITE_API_BASE_URL ?? 'http://localhost:8000/api/v1';

export interface ApiError {
  code: string;
  message: string;
  fields?: { field: string; rule: string }[];
}

export class ApiRequestError extends Error {
  constructor(public readonly error: ApiError) {
    super(error.message);
  }
}

const ACCESS_KEY = 'waselna_access_token';

// A small set of subscribers notified whenever the access token changes, so
// React components (via useAccessToken) re-render immediately on login/logout
// instead of waiting for a manual page refresh. localStorage on its own is not
// reactive.
type TokenListener = (token: string | null) => void;
const tokenListeners = new Set<TokenListener>();

export function subscribeToken(listener: TokenListener): () => void {
  tokenListeners.add(listener);
  return () => tokenListeners.delete(listener);
}

export function setAccessToken(token: string | null): void {
  if (token === null) {
    localStorage.removeItem(ACCESS_KEY);
  } else {
    localStorage.setItem(ACCESS_KEY, token);
  }
  for (const listener of tokenListeners) {
    listener(token);
  }
}

export function getAccessToken(): string | null {
  return localStorage.getItem(ACCESS_KEY);
}

export async function apiFetch<T>(
  path: string,
  options: { method?: string; body?: unknown; auth?: boolean; headers?: Record<string, string> } = {},
): Promise<T> {
  const headers: Record<string, string> = { 'Content-Type': 'application/json', ...options.headers };
  const token = getAccessToken();
  if (options.auth !== false && token) {
    headers.Authorization = `Bearer ${token}`;
  }

  const response = await fetch(`${BASE_URL}${path}`, {
    method: options.method ?? 'GET',
    headers,
    body: options.body === undefined ? undefined : JSON.stringify(options.body),
  });

  const payload = await response.json().catch(() => null);

  if (!response.ok) {
    const err: ApiError = payload?.error ?? { code: 'internal_error', message: 'Request failed.' };
    throw new ApiRequestError(err);
  }

  return payload?.data as T;
}
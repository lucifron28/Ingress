import { AuthStorage } from './auth-storage';

/**
 * Development fallback for the Ingress cloud API base URL.
 *
 * Production builds set this to the cloud HTTPS origin. Demo builds may use the
 * legacy local server setting. Event Node staff traffic uses its own paired
 * session and URL in node-session.ts.
 */
export const DEFAULT_API_URL =
  process.env.EXPO_PUBLIC_API_URL?.trim().replace(/\/+$/, '') || 'http://localhost:8000/api/v1';

/**
 * Whether this build may advertise the synthetic demonstration accounts.
 *
 * This is a build-time flag and only controls client-side conveniences: it offers
 * the demo quick-select and pre-fills the known demo password. It cannot make the
 * server any less safe — the server decides whether demo accounts exist at all
 * via INGRESS_DEMO_MODE. Defaults to false so a production build has to opt in
 * explicitly to showing predictable credentials.
 */
export const DEMO_MODE = process.env.EXPO_PUBLIC_INGRESS_DEMO_MODE === 'true';

export interface ApiErrorData {
  status?: string;
  message?: string;
  detail?: string;
  errors?: Record<string, string[]>;
  previous_admission?: {
    admitted_at: string;
    access_point: string;
    scanned_by?: string;
  };
  ticket?: {
    public_id: string;
    attendee_name: string;
    student_id?: string | null;
  };
  [key: string]: unknown;
}

export class ApiError extends Error {
  readonly status: number;
  readonly domainStatus?: string;
  readonly detail?: string;
  readonly errors?: Record<string, string[]>;
  readonly data?: ApiErrorData;

  constructor(
    message: string,
    status: number,
    data?: ApiErrorData,
    errors?: Record<string, string[]>
  ) {
    super(message);
    this.name = 'ApiError';
    this.status = status;
    this.data = data;
    this.domainStatus = data?.status;
    this.detail = data?.detail;
    this.errors = errors || data?.errors;
    Object.setPrototypeOf(this, ApiError.prototype);
  }
}

let unauthorizedHandler: ((token: string) => Promise<void>) | null = null;

export function setUnauthorizedHandler(handler: ((token: string) => Promise<void>) | null): void {
  unauthorizedHandler = handler;
}

export async function getActiveApiUrl(): Promise<string> {
  const saved = await AuthStorage.getServerUrl();
  return (saved && saved.trim().length > 0) ? saved.trim().replace(/\/+$/, '') : DEFAULT_API_URL;
}

export async function apiRequest<T = unknown>(
  path: string,
  options: RequestInit = {}
): Promise<T> {
  const baseUrl = await getActiveApiUrl();
  const token = await AuthStorage.getToken();
  const url = `${baseUrl}${path.startsWith('/') ? path : `/${path}`}`;

  const headers: Record<string, string> = {
    'Accept': 'application/json',
    'Content-Type': 'application/json',
    ...(options.headers as Record<string, string> || {}),
  };

  if (token) {
    headers['Authorization'] = `Bearer ${token}`;
  }

  try {
    const controller = new AbortController();
    const timeoutId = setTimeout(() => controller.abort(), 8000);

    const response = await fetch(url, {
      ...options,
      headers,
      signal: controller.signal,
    });

    clearTimeout(timeoutId);

    const data: unknown = await response.json().catch(() => null);

    if (!response.ok) {
      if (response.status === 401 && token && path !== '/auth/login' && unauthorizedHandler) {
        try { await unauthorizedHandler(token); } catch { /* Preserve the server error. */ }
      }
      const errorPayload = (data && typeof data === 'object') ? (data as ApiErrorData) : undefined;
      const message = errorPayload?.message || `Request failed with status ${response.status}`;
      throw new ApiError(message, response.status, errorPayload, errorPayload?.errors);
    }

    return data as T;
  } catch (err: unknown) {
    if (err instanceof ApiError) {
      throw err;
    }
    if (err && typeof err === 'object' && 'status' in err) {
      throw err;
    }
    const networkError = new ApiError(
      'SERVER UNAVAILABLE. Reconnect to the event network before scanning.',
      0,
      { status: 'unavailable', message: 'SERVER UNAVAILABLE. Reconnect to the event network before scanning.' }
    );
    throw networkError;
  }
}

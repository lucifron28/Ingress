import { AuthStorage } from './auth-storage';

/**
 * Development fallback for the Ingress Event Node base URL.
 *
 * The runtime LAN address is configured per device through the Server Settings
 * screen and persisted in secure storage. This constant is only the initial
 * value used before a device has been configured, and can be overridden at
 * build time with the EXPO_PUBLIC_API_URL environment variable.
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

export interface ApiError {
  message: string;
  errors?: Record<string, string[]>;
  status: number;
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
      const errorPayload = data as { message?: string; errors?: Record<string, string[]> } | null;
      const error: ApiError = {
        message: errorPayload?.message || `Request failed with status ${response.status}`,
        errors: errorPayload?.errors,
        status: response.status,
      };
      throw error;
    }

    return data as T;
  } catch (err: unknown) {
    if (err && typeof err === 'object' && 'status' in err) {
      throw err as ApiError;
    }
    // Fail-closed network error
    const networkError: ApiError = {
      message: 'SERVER UNAVAILABLE. Reconnect to the event network before scanning.',
      status: 0,
    };
    throw networkError;
  }
}

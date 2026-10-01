import { AuthStorage } from './auth-storage';

export const DEFAULT_API_URL = 'http://192.168.55.128:8000/api/v1';

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

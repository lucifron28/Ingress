import { fetch as expoFetch } from 'expo/fetch';
import * as SecureStore from 'expo-secure-store';
import { Platform } from 'react-native';
import { ApiError } from './api-client';
import type { ApiErrorData } from './api-client';
import type { UserProfile } from '../context/AuthContext';

export interface NodeSession {
  url: string;
  token: string;
  user: UserProfile;
}

const NODE_SESSION_KEY = 'ingress_node_staff_session_v1';
const NODE_OPTIONS = { keychainAccessible: SecureStore.WHEN_UNLOCKED_THIS_DEVICE_ONLY };
let nodeQueue: Promise<void> = Promise.resolve();
let onUnauthorized: ((token: string) => void) | null = null;

function serialize<T>(operation: () => Promise<T>): Promise<T> {
  const result = nodeQueue.then(operation, operation);
  nodeQueue = result.then(() => undefined, () => undefined);
  return result;
}

export function normalizeNodeUrl(input: string): string {
  const url = new URL(input.trim());
  if (url.protocol !== 'https:' || !url.hostname || url.username || url.password || url.search || url.hash || input.length > 512 || !/^(?:\/[A-Za-z0-9_-]+)*\/api\/v1\/?$/.test(url.pathname)) {
    throw new Error('Enter the HTTPS Event Node API address ending in /api/v1.');
  }
  return url.toString().replace(/\/+$/, '');
}

function validSession(value: unknown): value is NodeSession {
  if (!value || typeof value !== 'object') return false;
  const session = value as NodeSession;
  try {
    return typeof session.token === 'string' && session.token.length > 0 &&
      Number.isSafeInteger(session.user?.id) && session.user.id > 0 && typeof session.user?.name === 'string' &&
      typeof session.user?.email === 'string' && session.token.length <= 256 &&
      typeof session.url === 'string' && normalizeNodeUrl(session.url) === session.url;
  } catch { return false; }
}

async function readUnsafe(): Promise<NodeSession | null> {
  if (Platform.OS === 'web') return null;
  const raw = await SecureStore.getItemAsync(NODE_SESSION_KEY, NODE_OPTIONS);
  try {
    const session: unknown = raw ? JSON.parse(raw) : null;
    return validSession(session) ? session : null;
  } catch { return null; }
}

export function getNodeSession(): Promise<NodeSession | null> {
  return serialize(readUnsafe);
}

export function setNodeUnauthorizedHandler(handler: ((token: string) => void) | null): void {
  onUnauthorized = handler;
}

async function clearIfToken(token: string): Promise<boolean> {
  return serialize(async () => {
    const current = await readUnsafe();
    if (current?.token !== token) return false;
    await SecureStore.deleteItemAsync(NODE_SESSION_KEY, NODE_OPTIONS);
    return true;
  });
}

async function request<T>(url: string, path: string, token: string | null, options: RequestInit = {}): Promise<T> {
  if (!/^\/[A-Za-z0-9/_-]+$/.test(path) || path.startsWith('//')) throw new Error('Invalid node request path.');
  const base = new URL(normalizeNodeUrl(url));
  const target = new URL(`${base.toString().replace(/\/+$/, '')}${path}`);
  if (target.origin !== base.origin || !target.pathname.startsWith(`${base.pathname.replace(/\/+$/, '')}/`)) {
    throw new Error('Invalid node request path.');
  }
  const headers: Record<string, string> = {
    'Accept': 'application/json', 'Content-Type': 'application/json',
    ...(options.headers as Record<string, string> || {}),
  };
  delete headers.authorization;
  delete headers.Authorization;
  if (token) headers.Authorization = `Bearer ${token}`;
  const controller = new AbortController();
  const timeout = setTimeout(() => controller.abort(), 8000);
  try {
    const response = await expoFetch(target.toString(), {
      ...options, headers, credentials: 'omit', redirect: 'error', signal: controller.signal,
    });
    const data: unknown = await response.json().catch(() => null);
    if (!response.ok) {
      if (response.status === 401 && token) {
        try { if (await clearIfToken(token)) onUnauthorized?.(token); } catch { onUnauthorized?.(token); }
      }
      const body = data && typeof data === 'object' ? data as ApiErrorData : undefined;
      throw new ApiError(body?.message || `Request failed with status ${response.status}`, response.status, body, body?.errors);
    }
    return data as T;
  } catch (error: unknown) {
    if (error instanceof ApiError) throw error;
    throw new ApiError('Cannot reach the Event Node. Check the venue network and certificate.', 0);
  } finally {
    clearTimeout(timeout);
  }
}

export interface NodePairingCode {
  v: 1;
  url: string;
  node: string;
  token: string;
  expires: string;
}

export function parseNodePairingCode(raw: string): NodePairingCode {
  try {
    if (raw.length > 1024) throw new Error();
    const value: unknown = JSON.parse(raw);
    if (!value || typeof value !== 'object' || Array.isArray(value)) throw new Error();
    const code = value as NodePairingCode;
    if (Object.keys(code).sort().join(',') !== 'expires,node,token,url,v' || code.v !== 1 ||
        typeof code.node !== 'string' || !/^[a-zA-Z0-9_-]{1,64}$/.test(code.node) ||
        typeof code.token !== 'string' || !/^[a-f0-9]{64}$/.test(code.token) ||
        typeof code.expires !== 'string' || !/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/.test(code.expires) ||
        !Number.isFinite(Date.parse(code.expires)) || typeof code.url !== 'string') throw new Error();
    return { ...code, url: normalizeNodeUrl(code.url) };
  } catch {
    throw new Error('This is not a valid Event Node pairing code.');
  }
}

async function saveNodeSession(baseUrl: string, response: { token: string; user: UserProfile; node_staff: boolean }, previous: NodeSession | null): Promise<NodeSession> {
  const session = { url: baseUrl, token: response.token, user: response.user };
  if (response.node_staff !== true || !validSession(session)) throw new Error('The Event Node returned an invalid session.');
  try {
    await serialize(() => SecureStore.setItemAsync(NODE_SESSION_KEY, JSON.stringify(session), NODE_OPTIONS));
  } catch {
    try { await request(baseUrl, '/auth/logout', session.token, { method: 'POST' }); } catch { /* Best effort. */ }
    throw new Error('Secure storage is unavailable. Staff sign-in was not saved.');
  }
  if (previous && previous.token !== session.token) {
    try { await request(previous.url, '/auth/logout', previous.token, { method: 'POST' }); } catch { /* Best effort. */ }
  }
  return session;
}

export async function pairNodeStaff(code: NodePairingCode): Promise<NodeSession> {
  if (Platform.OS === 'web') throw new Error('Node staff pairing is available in the mobile app.');
  const parsed = parseNodePairingCode(JSON.stringify(code));
  const previous = await getNodeSession();
  const response = await request<{ token: string; user: UserProfile; node_staff: boolean; node_id: string }>(parsed.url, '/auth/node-staff/pair', null, {
    method: 'POST', body: JSON.stringify({ node_id: parsed.node, token: parsed.token, device_name: 'Ingress-Scanner' }),
  });
  if (response.node_id !== parsed.node) {
    if (typeof response.token === 'string' && response.token.length > 0 && response.token.length <= 256) {
      try { await request(parsed.url, '/auth/logout', response.token, { method: 'POST' }); } catch { /* Best effort. */ }
    }
    throw new Error('The Event Node identity did not match the pairing code.');
  }
  return saveNodeSession(parsed.url, response, previous);
}

export async function loginNodeStaff(url: string, email: string, password: string): Promise<NodeSession> {
  if (Platform.OS === 'web') throw new Error('Node staff sign-in is available in the mobile app.');
  const baseUrl = normalizeNodeUrl(url);
  const previous = await getNodeSession();
  const response = await request<{ token: string; user: UserProfile; node_staff: boolean }>(baseUrl, '/auth/node-staff/login', null, {
    method: 'POST', body: JSON.stringify({ email: email.trim(), password, device_name: 'Ingress-Scanner' }),
  });
  return saveNodeSession(baseUrl, response, previous);
}

export async function nodeRequest<T = unknown>(path: string, options: RequestInit = {}): Promise<T> {
  const session = await getNodeSession();
  if (!session) throw new ApiError('Sign in to the Event Node first.', 401);
  return request<T>(session.url, path, session.token, options);
}

export async function logoutNodeStaff(): Promise<void> {
  const session = await getNodeSession();
  if (!session) return;
  await clearIfToken(session.token);
  try { await request(session.url, '/auth/logout', session.token, { method: 'POST' }); } catch { /* Best effort. */ }
}

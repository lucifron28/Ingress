import * as SecureStore from 'expo-secure-store';
import { Platform } from 'react-native';

const TOKEN_KEY = 'ingress_auth_token';
const SERVER_URL_KEY = 'ingress_server_url';
const USER_KEY = 'ingress_user_profile';

// In-memory fallback for web or unsupported runtimes
const memoryStorage: Record<string, string> = {};

export async function saveItem(key: string, value: string): Promise<void> {
  if (Platform.OS === 'web') {
    try {
      localStorage.setItem(key, value);
    } catch {
      memoryStorage[key] = value;
    }
    return;
  }

  try {
    await SecureStore.setItemAsync(key, value);
  } catch {
    memoryStorage[key] = value;
  }
}

export async function getItem(key: string): Promise<string | null> {
  if (Platform.OS === 'web') {
    try {
      return localStorage.getItem(key) ?? memoryStorage[key] ?? null;
    } catch {
      return memoryStorage[key] ?? null;
    }
  }

  try {
    return await SecureStore.getItemAsync(key);
  } catch {
    return memoryStorage[key] ?? null;
  }
}

export async function deleteItem(key: string): Promise<void> {
  if (Platform.OS === 'web') {
    try {
      localStorage.removeItem(key);
    } catch {
      delete memoryStorage[key];
    }
    return;
  }

  try {
    await SecureStore.deleteItemAsync(key);
  } catch {
    delete memoryStorage[key];
  }
}

export interface WalletScope {
  userId: number;
  token: string;
  serverUrl: string;
}

export interface WalletTicket {
  id: number;
  public_id: string;
  status: string;
  issued_at: string;
  qr_payload?: string | null;
  event: {
    id: number;
    title: string;
    status?: string;
    ticket_transfers_allowed?: boolean;
    venue?: { name: string; location: string | null } | null;
    starts_at: string;
    ends_at: string;
  };
  attendee: { name: string; student_id: string | null; email: string };
}

interface WalletHeader {
  version: 1;
  valid: boolean;
  count: number;
  scope: WalletScope;
  savedAt: string;
}

const WALLET_HEADER_KEY = 'ingress_wallet_header';
const WALLET_OPTIONS = { keychainAccessible: SecureStore.WHEN_UNLOCKED_THIS_DEVICE_ONLY };
const walletChunkKey = (index: number) => `ingress_wallet_${index}`;
let walletQueue: Promise<void> = Promise.resolve();
let walletRequest = 0;
let lastWalletResponse = 0;

function serializeWallet<T>(operation: () => Promise<T>): Promise<T> {
  const result = walletQueue.then(operation, operation);
  walletQueue = result.then(() => undefined, () => undefined);
  return result;
}

async function readWalletHeader(): Promise<WalletHeader | null> {
  const value = await SecureStore.getItemAsync(WALLET_HEADER_KEY, WALLET_OPTIONS);
  if (!value) return null;
  try {
    const header = JSON.parse(value) as WalletHeader;
    if (header.version !== 1 || !Number.isSafeInteger(header.count) || header.count < 0 || header.count > 2048) return null;
    return header;
  } catch {
    return null;
  }
}

async function scopeMatches(scope: WalletScope): Promise<boolean> {
  if (Platform.OS === 'web' || !scope.token || !Number.isSafeInteger(scope.userId)) return false;
  const [token, userJson, savedUrl] = await Promise.all([
    SecureStore.getItemAsync(TOKEN_KEY), SecureStore.getItemAsync(USER_KEY), SecureStore.getItemAsync(SERVER_URL_KEY),
  ]);
  try {
    return token === scope.token && JSON.parse(userJson || 'null')?.id === scope.userId &&
      (savedUrl === null || savedUrl.replace(/\/+$/, '') === scope.serverUrl);
  } catch {
    return false;
  }
}

async function clearWalletUnsafe(): Promise<void> {
  if (Platform.OS === 'web') return;
  const header = await readWalletHeader();
  if (!header) return;
  await SecureStore.setItemAsync(WALLET_HEADER_KEY, JSON.stringify({ ...header, valid: false }), WALLET_OPTIONS);
  for (let i = 0; i < header.count; i++) await SecureStore.deleteItemAsync(walletChunkKey(i), WALLET_OPTIONS);
  await SecureStore.deleteItemAsync(WALLET_HEADER_KEY, WALLET_OPTIONS);
}

function normalizeServerUrl(url: string | null): string | null {
  return url?.trim().replace(/\/+$/, '') || null;
}

async function enforceReleaseServerUrl(configuredUrl: string): Promise<void> {
  const canonicalUrl = configuredUrl.trim().replace(/\/+$/, '');

  await serializeWallet(async () => {
    const savedUrl = normalizeServerUrl(await getItem(SERVER_URL_KEY));
    if (savedUrl === canonicalUrl) return;

    if (Platform.OS === 'web') {
      await deleteItem(TOKEN_KEY);
      await deleteItem(USER_KEY);
      await saveItem(SERVER_URL_KEY, canonicalUrl);
      return;
    }

    await clearWalletUnsafe();
    await SecureStore.deleteItemAsync(TOKEN_KEY);
    await SecureStore.deleteItemAsync(USER_KEY);
    await SecureStore.setItemAsync(SERVER_URL_KEY, canonicalUrl);
  });
}

async function readWalletUnsafe(scope: WalletScope): Promise<{ tickets: WalletTicket[]; savedAt: string } | null> {
  if (!(await scopeMatches(scope))) return null;
  const header = await readWalletHeader();
  if (!header?.valid || header.scope?.token !== scope.token || header.scope?.userId !== scope.userId || header.scope?.serverUrl !== scope.serverUrl) return null;
  const parts: string[] = [];
  for (let i = 0; i < header.count; i++) {
    const part = await SecureStore.getItemAsync(walletChunkKey(i), WALLET_OPTIONS);
    if (part === null) return null;
    parts.push(part);
  }
  try {
    const tickets: unknown = JSON.parse(parts.join(''));
    return Array.isArray(tickets) ? { tickets: tickets as WalletTicket[], savedAt: header.savedAt } : null;
  } catch {
    return null;
  }
}

async function saveWalletUnsafe(scope: WalletScope, tickets: WalletTicket[], savedAt = new Date().toISOString()): Promise<boolean> {
  if (!(await scopeMatches(scope))) return false;
  const chars = Array.from(JSON.stringify(tickets));
  const parts: string[] = [];
  for (let i = 0; i < chars.length; i += 400) parts.push(chars.slice(i, i + 400).join(''));
  if (parts.length > 2048) {
    await clearWalletUnsafe();
    return false;
  }
  const old = await readWalletHeader();
  const header: WalletHeader = { version: 1, valid: false, count: Math.max(old?.count || 0, parts.length), scope, savedAt };
  await SecureStore.setItemAsync(WALLET_HEADER_KEY, JSON.stringify(header), WALLET_OPTIONS);
  for (let i = 0; i < parts.length; i++) await SecureStore.setItemAsync(walletChunkKey(i), parts[i], WALLET_OPTIONS);
  for (let i = parts.length; i < header.count; i++) await SecureStore.deleteItemAsync(walletChunkKey(i), WALLET_OPTIONS);
  if (!(await scopeMatches(scope))) return false;
  await SecureStore.setItemAsync(WALLET_HEADER_KEY, JSON.stringify({ ...header, valid: true, count: parts.length }), WALLET_OPTIONS);
  return true;
}

export const TicketWallet = {
  beginRequest: () => ++walletRequest,
  read: (scope: WalletScope) => serializeWallet(async () => {
    try { return await readWalletUnsafe(scope); } catch { return null; }
  }),
  replace: (scope: WalletScope, tickets: WalletTicket[], request = ++walletRequest) => serializeWallet(async () => {
    if (request < lastWalletResponse) return false;
    lastWalletResponse = request;
    try { return await saveWalletUnsafe(scope, tickets); } catch { return false; }
  }),
  update: (scope: WalletScope, ticket: WalletTicket, request = ++walletRequest) => serializeWallet(async () => {
    if (request < lastWalletResponse) return false;
    lastWalletResponse = request;
    try {
      const wallet = await readWalletUnsafe(scope);
      if (!wallet) return false;
      const tickets = wallet.tickets.map((item) => item.id === ticket.id ? ticket : item);
      return await saveWalletUnsafe(scope, tickets, wallet.savedAt);
    } catch { return false; }
  }),
  invalidate: (scope: WalletScope) => serializeWallet(async () => {
    try { if (await scopeMatches(scope)) await clearWalletUnsafe(); } catch { /* Storage may be unavailable. */ }
  }),
};

export const AuthStorage = {
  saveToken: (token: string) => saveItem(TOKEN_KEY, token),
  getToken: () => getItem(TOKEN_KEY),
  removeToken: () => deleteItem(TOKEN_KEY),

  saveServerUrl: (url: string) => saveItem(SERVER_URL_KEY, url),
  getServerUrl: () => getItem(SERVER_URL_KEY),
  enforceReleaseServerUrl,

  saveUser: (userJson: string) => saveItem(USER_KEY, userJson),
  getUser: () => getItem(USER_KEY),
  removeUser: () => deleteItem(USER_KEY),

  clearIfToken: (token: string) => serializeWallet(async () => {
    if ((await getItem(TOKEN_KEY)) !== token) return false;
    try { await clearWalletUnsafe(); } catch { /* Session keys are still removed below. */ }
    await deleteItem(TOKEN_KEY);
    await deleteItem(USER_KEY);
    return true;
  }),

  clearAll: () => serializeWallet(async () => {
    try { await clearWalletUnsafe(); } catch { /* Session keys are still removed below. */ }
    await deleteItem(TOKEN_KEY);
    await deleteItem(USER_KEY);
  }),
};

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

export const AuthStorage = {
  saveToken: (token: string) => saveItem(TOKEN_KEY, token),
  getToken: () => getItem(TOKEN_KEY),
  removeToken: () => deleteItem(TOKEN_KEY),

  saveServerUrl: (url: string) => saveItem(SERVER_URL_KEY, url),
  getServerUrl: () => getItem(SERVER_URL_KEY),

  saveUser: (userJson: string) => saveItem(USER_KEY, userJson),
  getUser: () => getItem(USER_KEY),
  removeUser: () => deleteItem(USER_KEY),

  clearAll: async () => {
    await deleteItem(TOKEN_KEY);
    await deleteItem(USER_KEY);
  },
};

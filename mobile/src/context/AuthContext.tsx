import React, { createContext, useContext, useEffect, useState } from 'react';
import { apiRequest, DEFAULT_API_URL } from '../services/api-client';
import { AuthStorage } from '../services/auth-storage';

export interface UserProfile {
  id: number;
  name: string;
  email: string;
  role: 'student' | 'staff' | 'organizer' | 'admin';
  student_id: string | null;
  created_at?: string;
}

interface LoginResponse {
  token: string;
  token_type: string;
  user: UserProfile;
}

export interface RegisterParams {
  name: string;
  student_id: string;
  email: string;
  password: string;
  password_confirmation: string;
}

interface AuthContextType {
  user: UserProfile | null;
  token: string | null;
  serverUrl: string;
  isLoading: boolean;
  login: (email: string, password: string) => Promise<void>;
  logout: () => Promise<void>;
  register: (params: RegisterParams) => Promise<void>;
  /**
   * Points the app at a different Event Node. Resolves to true when the change
   * invalidated the current session, so the caller can send the user to login.
   */
  updateServerUrl: (url: string) => Promise<boolean>;
}

const AuthContext = createContext<AuthContextType | undefined>(undefined);

export const AuthProvider: React.FC<{ children: React.ReactNode }> = ({ children }) => {
  const [user, setUser] = useState<UserProfile | null>(null);
  const [token, setToken] = useState<string | null>(null);
  const [serverUrl, setServerUrl] = useState<string>(DEFAULT_API_URL);
  const [isLoading, setIsLoading] = useState<boolean>(true);

  useEffect(() => {
    async function restoreSession() {
      try {
        const storedUrl = await AuthStorage.getServerUrl();
        if (storedUrl) {
          setServerUrl(storedUrl);
        }

        const storedToken = await AuthStorage.getToken();
        const storedUser = await AuthStorage.getUser();

        if (storedToken && storedUser) {
          setToken(storedToken);
          setUser(JSON.parse(storedUser) as UserProfile);

          try {
            const meRes = await apiRequest<{ user: UserProfile }>('/me');
            setUser(meRes.user);
            await AuthStorage.saveUser(JSON.stringify(meRes.user));
          } catch (err: unknown) {
            const status = (err as { status?: number } | null)?.status;

            if (status === 401) {
              // A rejected token is no longer a valid local session.
              setToken(null);
              setUser(null);
              await AuthStorage.clearAll();
            }

            // Keep cached identity when the Event Node is unreachable; server actions still fail.
          }
        }
      } catch {
        // Fallback gracefully on initialization error
      } finally {
        setIsLoading(false);
      }
    }

    restoreSession();
  }, []);

  const login = async (email: string, password: string): Promise<void> => {
    setIsLoading(true);
    try {
      const response = await apiRequest<LoginResponse>('/auth/login', {
        method: 'POST',
        body: JSON.stringify({ email, password, device_name: 'Ingress-Mobile' }),
      });

      setToken(response.token);
      setUser(response.user);

      await AuthStorage.saveToken(response.token);
      await AuthStorage.saveUser(JSON.stringify(response.user));
    } finally {
      setIsLoading(false);
    }
  };

  const register = async (params: RegisterParams): Promise<void> => {
    setIsLoading(true);
    try {
      const response = await apiRequest<LoginResponse>('/auth/register', {
        method: 'POST',
        body: JSON.stringify({ ...params, device_name: 'Ingress-Mobile' }),
      });

      setToken(response.token);
      setUser(response.user);

      await AuthStorage.saveToken(response.token);
      await AuthStorage.saveUser(JSON.stringify(response.user));
    } finally {
      setIsLoading(false);
    }
  };

  const logout = async (): Promise<void> => {
    try {
      await apiRequest('/auth/logout', { method: 'POST' });
    } catch {
      // Best-effort server logout
    } finally {
      setToken(null);
      setUser(null);
      await AuthStorage.clearAll();
    }
  };

  const updateServerUrl = async (newUrl: string): Promise<boolean> => {
    const formatted = newUrl.trim().replace(/\/+$/, '');
    const isChange = formatted !== serverUrl;

    // Tokens are Event Node-specific. Switching servers invalidates the local session.
    if (isChange) {
      setToken(null);
      setUser(null);
      await AuthStorage.clearAll();
    }

    setServerUrl(formatted);
    await AuthStorage.saveServerUrl(formatted);

    return isChange;
  };

  return (
    <AuthContext.Provider
      value={{
        user,
        token,
        serverUrl,
        isLoading,
        login,
        logout,
        updateServerUrl,
        register,
      }}
    >
      {children}
    </AuthContext.Provider>
  );
};

export function useAuth(): AuthContextType {
  const context = useContext(AuthContext);
  if (!context) {
    throw new Error('useAuth must be used within an AuthProvider');
  }
  return context;
}

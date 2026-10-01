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

interface AuthContextType {
  user: UserProfile | null;
  token: string | null;
  serverUrl: string;
  isLoading: boolean;
  login: (email: string, password: string) => Promise<void>;
  logout: () => Promise<void>;
  updateServerUrl: (url: string) => Promise<void>;
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

          // Confirm the stored token is still valid for this server.
          try {
            const meRes = await apiRequest<{ user: UserProfile }>('/me');
            setUser(meRes.user);
            await AuthStorage.saveUser(JSON.stringify(meRes.user));
          } catch (err: unknown) {
            const status = (err as { status?: number } | null)?.status;

            if (status === 401) {
              // The server rejected the token (revoked, expired, or issued by a
              // different Event Node). Drop the session entirely.
              setToken(null);
              setUser(null);
              await AuthStorage.clearAll();
            }

            // Any other failure means the server was unreachable. Keep the cached
            // identity so the app can still say who is signed in; every server
            // action remains fail-closed through apiRequest.
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

  const updateServerUrl = async (newUrl: string): Promise<void> => {
    const formatted = newUrl.trim().replace(/\/+$/, '');
    setServerUrl(formatted);
    await AuthStorage.saveServerUrl(formatted);
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

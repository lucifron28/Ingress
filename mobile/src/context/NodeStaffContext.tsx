import { router } from 'expo-router';
import React, { createContext, useContext, useEffect, useRef, useState } from 'react';
import { getNodeSession, loginNodeStaff, logoutNodeStaff, nodeRequest, setNodeUnauthorizedHandler } from '../services/node-session';
import type { NodeSession } from '../services/node-session';
import type { UserProfile } from './AuthContext';

interface NodeStaffContextValue {
  session: NodeSession | null;
  active: boolean;
  login: (url: string, email: string, password: string) => Promise<void>;
  resume: () => Promise<void>;
  logout: () => Promise<void>;
}

const NodeStaffContext = createContext<NodeStaffContextValue | undefined>(undefined);

export function NodeStaffProvider({ children }: { children: React.ReactNode }) {
  const [session, setSession] = useState<NodeSession | null>(null);
  const [active, setActive] = useState(false);
  const current = useRef<NodeSession | null>(null);

  useEffect(() => {
    setNodeUnauthorizedHandler((token) => {
      if (current.current?.token !== token) return;
      current.current = null;
      setSession(null);
      router.replace('/node-staff-login');
    });
    return () => setNodeUnauthorizedHandler(null);
  }, []);

  const login = async (url: string, email: string, password: string) => {
    const next = await loginNodeStaff(url, email, password);
    current.current = next;
    setSession(next);
    setActive(true);
  };

  const resume = async () => {
    const saved = await getNodeSession();
    if (!saved) throw new Error('No saved Event Node session. Sign in again.');
    const response = await nodeRequest<{ user: UserProfile }>('/me');
    if (response.user?.id !== saved.user.id) {
      await logoutNodeStaff();
      throw new Error('The Event Node session does not match the saved staff account.');
    }
    const next = { ...saved, user: response.user };
    current.current = next;
    setSession(next);
    setActive(true);
  };

  const logout = async () => {
    await logoutNodeStaff();
    current.current = null;
    setSession(null);
    setActive(false);
  };

  return <NodeStaffContext.Provider value={{ session, active, login, resume, logout }}>{children}</NodeStaffContext.Provider>;
}

export function useNodeStaff(): NodeStaffContextValue {
  const context = useContext(NodeStaffContext);
  if (!context) throw new Error('useNodeStaff must be used within a NodeStaffProvider');
  return context;
}

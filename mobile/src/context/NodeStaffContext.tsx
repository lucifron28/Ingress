import { router } from 'expo-router';
import React, { createContext, useContext, useEffect, useReducer, useRef } from 'react';
import { getNodeSession, loginNodeStaff, logoutNodeStaff, nodeRequest, pairNodeStaff, setNodeUnauthorizedHandler } from '../services/node-session';
import { INITIAL_NODE_STAFF_STATE, nodeStaffReducer } from '../services/node-staff-state';
import type { NodePairingCode, NodeSession, NodeStaffUser } from '../services/node-session';

interface NodeStaffContextValue {
  session: NodeSession | null;
  active: boolean;
  login: (url: string, email: string, password: string) => Promise<void>;
  pair: (code: NodePairingCode) => Promise<void>;
  resume: () => Promise<void>;
  logout: () => Promise<void>;
}

const NodeStaffContext = createContext<NodeStaffContextValue | undefined>(undefined);

export function NodeStaffProvider({ children }: { children: React.ReactNode }) {
  const [state, dispatch] = useReducer(nodeStaffReducer, INITIAL_NODE_STAFF_STATE);
  const { session, active } = state;
  const current = useRef<NodeSession | null>(null);

  useEffect(() => {
    setNodeUnauthorizedHandler((token) => {
      if (current.current?.token !== token) return;
      current.current = null;
      dispatch({ type: 'unauthorized', token });
      router.replace('/node-staff-login');
    });
    return () => setNodeUnauthorizedHandler(null);
  }, []);

  const login = async (url: string, email: string, password: string) => {
    const next = await loginNodeStaff(url, email, password);
    current.current = next;
    dispatch({ type: 'activate', session: next });
  };

  const pair = async (code: NodePairingCode) => {
    const next = await pairNodeStaff(code);
    current.current = next;
    dispatch({ type: 'activate', session: next });
  };

  const resume = async () => {
    const saved = await getNodeSession();
    if (!saved) throw new Error('No saved Event Node session. Sign in again.');
    const response = await nodeRequest<{ user: NodeStaffUser }>('/me');
    if (response.user?.id !== saved.user.id) {
      await logoutNodeStaff();
      throw new Error('The Event Node session does not match the saved staff account.');
    }
    const next = { ...saved, user: response.user };
    current.current = next;
    dispatch({ type: 'activate', session: next });
  };

  const logout = async () => {
    await logoutNodeStaff();
    current.current = null;
    dispatch({ type: 'deactivate' });
  };

  return <NodeStaffContext.Provider value={{ session, active, login, pair, resume, logout }}>{children}</NodeStaffContext.Provider>;
}

export function useNodeStaff(): NodeStaffContextValue {
  const context = useContext(NodeStaffContext);
  if (!context) throw new Error('useNodeStaff must be used within a NodeStaffProvider');
  return context;
}

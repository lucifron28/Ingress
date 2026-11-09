import type { NodeSession } from './node-session';

export interface NodeStaffState {
  session: NodeSession | null;
  active: boolean;
}

export type NodeStaffStateAction =
  | { type: 'activate'; session: NodeSession }
  | { type: 'deactivate' }
  | { type: 'unauthorized'; token: string };

export const INITIAL_NODE_STAFF_STATE: NodeStaffState = {
  session: null,
  active: false,
};

export function nodeStaffReducer(
  state: NodeStaffState,
  action: NodeStaffStateAction,
): NodeStaffState {
  switch (action.type) {
    case 'activate':
      return { session: action.session, active: true };
    case 'deactivate':
      return { session: null, active: false };
    case 'unauthorized':
      return state.session?.token === action.token
        ? { session: null, active: false }
        : state;
  }
}

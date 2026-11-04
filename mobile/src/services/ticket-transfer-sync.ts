import { apiRequest } from './api-client';

export interface TicketTransfer {
  id: number;
  status: 'pending' | 'accepted' | 'declined' | 'cancelled' | 'expired';
  direction: 'incoming' | 'outgoing';
  requested_at: string;
  sender: { name: string; email: string };
  recipient: { name: string; email: string };
  ticket: {
    id: number | null;
    public_id: string;
    event: { id: number | null; title: string | null; starts_at: string | null };
  };
}

export async function loadTicketTransfers(): Promise<TicketTransfer[]> {
  const response = await apiRequest<{ data: TicketTransfer[] }>('/me/ticket-transfers');
  return response.data || [];
}

export async function respondToTicketTransfer(id: number, decision: 'accept' | 'decline'): Promise<void> {
  await apiRequest(`/ticket-transfers/${id}/${decision}`, { method: 'POST' });
}

export async function cancelTicketTransfer(id: number): Promise<void> {
  await apiRequest(`/ticket-transfers/${id}`, { method: 'DELETE' });
}

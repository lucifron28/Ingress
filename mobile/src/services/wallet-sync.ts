import { apiRequest } from './api-client';
import { TicketWallet } from './auth-storage';
import type { WalletScope, WalletTicket } from './auth-storage';

export async function loadWallet(scope: WalletScope): Promise<{
  tickets: WalletTicket[];
  offlineSince: string | null;
  saved: boolean;
}> {
  const request = TicketWallet.beginRequest();
  try {
    const response = await apiRequest<{ data: WalletTicket[] }>('/me/tickets');
    const tickets = response.data || [];
    const saved = await TicketWallet.replace(scope, tickets, request);
    return { tickets, offlineSince: null, saved };
  } catch (error: unknown) {
    if ((error as { status?: number })?.status === 0) {
      const cached = await TicketWallet.read(scope);
      if (cached) return { tickets: cached.tickets, offlineSince: cached.savedAt, saved: true };
    } else {
      await TicketWallet.invalidate(scope);
    }
    throw error;
  }
}

export async function loadWalletTicket(scope: WalletScope, id: string): Promise<{
  ticket: WalletTicket;
  offlineSince: string | null;
}> {
  const request = TicketWallet.beginRequest();
  try {
    const response = await apiRequest<{ ticket: WalletTicket }>(`/tickets/${encodeURIComponent(id)}`);
    await TicketWallet.update(scope, response.ticket, request);
    return { ticket: response.ticket, offlineSince: null };
  } catch (error: unknown) {
    if ((error as { status?: number })?.status === 0) {
      const cached = await TicketWallet.read(scope);
      const ticket = cached?.tickets.find((item) => String(item.id) === id);
      if (ticket && cached) return { ticket, offlineSince: cached.savedAt };
    } else {
      await TicketWallet.invalidate(scope);
    }
    throw error;
  }
}

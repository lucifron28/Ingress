import { router } from 'expo-router';
import React, { useEffect, useRef, useState } from 'react';
import {
  ActivityIndicator,
  FlatList,
  Pressable,
  RefreshControl,
  StyleSheet,
  Text,
  View,
} from 'react-native';
import { loadWallet } from '../../../src/services/wallet-sync';
import { cancelTicketTransfer, loadTicketTransfers, respondToTicketTransfer } from '../../../src/services/ticket-transfer-sync';

import { useAuth } from '../../../src/context/AuthContext';
import { WalletTicket } from '../../../src/services/auth-storage';
import type { TicketTransfer } from '../../../src/services/ticket-transfer-sync';

type TicketItem = WalletTicket;

export default function MyTicketsScreen() {
  const { user, token, serverUrl } = useAuth();
  const requestId = useRef(0);
  const [tickets, setTickets] = useState<TicketItem[]>([]);
  const [offlineSince, setOfflineSince] = useState<string | null>(null);
  const [cacheUnavailable, setCacheUnavailable] = useState(false);
  const [isLoading, setIsLoading] = useState(true);
  const [isRefreshing, setIsRefreshing] = useState(false);
  const [errorMessage, setErrorMessage] = useState<string | null>(null);
  const [transfers, setTransfers] = useState<TicketTransfer[]>([]);
  const [transferMessage, setTransferMessage] = useState<string | null>(null);
  const [busyTransferId, setBusyTransferId] = useState<number | null>(null);

  const fetchTickets = async () => {
    const request = ++requestId.current;
    const scope = user && token ? { userId: user.id, token, serverUrl } : null;
    if (!scope) return;
    try {
      setErrorMessage(null);
      const result = await loadWallet(scope);
      if (request !== requestId.current) return;
      setTickets(result.tickets);
      setOfflineSince(result.offlineSince);
      setCacheUnavailable(!result.saved);
      try {
        setTransfers(await loadTicketTransfers());
      } catch {
        setTransfers([]);
      }
    } catch (err: unknown) {
      if (request !== requestId.current) return;
      const errorObj = err as { status?: number; message?: string };
      setTickets([]);
      setOfflineSince(null);
      setErrorMessage(errorObj?.status === 0 ? 'No saved tickets are available. Connect to load your wallet.' : errorObj?.message || 'Could not load your digital tickets.');
    } finally {
      if (request === requestId.current) {
        setIsLoading(false);
        setIsRefreshing(false);
      }
    }
  };

  const handleTransferAction = async (transfer: TicketTransfer, action: 'accept' | 'decline' | 'cancel') => {
    setBusyTransferId(transfer.id);
    setTransferMessage(null);
    try {
      if (action === 'cancel') await cancelTicketTransfer(transfer.id);
      else await respondToTicketTransfer(transfer.id, action);
      setTransferMessage(action === 'accept' ? 'Ticket transfer accepted. Your ticket is now in your wallet.' :
        action === 'decline' ? 'Ticket transfer declined.' : 'Transfer request cancelled.');
      await fetchTickets();
    } catch (error: unknown) {
      setTransferMessage((error as { message?: string })?.message || 'Could not update the transfer request.');
    } finally {
      setBusyTransferId(null);
    }
  };

  useEffect(() => {
    if (user && token) {
      setIsLoading(true);
      fetchTickets();
    } else {
      setTickets([]);
      setOfflineSince(null);
      setIsLoading(false);
    }
    return () => { requestId.current++; };
  }, [user?.id, token, serverUrl]);

  const formatDate = (isoString: string) => {
    try {
      const date = new Date(isoString);
      return date.toLocaleDateString('en-US', {
        month: 'short',
        day: 'numeric',
        year: 'numeric',
        hour: 'numeric',
        minute: '2-digit',
      });
    } catch {
      return isoString;
    }
  };

  const renderTicketCard = ({ item }: { item: TicketItem }) => (
    <Pressable
      style={({ pressed }) => [styles.ticketCard, pressed && styles.cardPressed]}
      onPress={() => router.push(`/(attendee)/tickets/${item.id}`)}
    >
      <View style={styles.cardHeader}>
        <Text style={styles.venueText}>{item.event.venue?.name || 'MSEUF Campus'}</Text>
        <View style={item.status === 'active' && item.event.status !== 'cancelled' ? styles.activeBadge : styles.revokedBadge}>
          <Text style={item.status === 'active' && item.event.status !== 'cancelled' ? styles.activeBadgeText : styles.revokedBadgeText}>
            {item.event.status === 'cancelled' ? 'EVENT CANCELLED' : item.status.toUpperCase()}
          </Text>
        </View>
      </View>

      <Text style={styles.eventTitle}>{item.event.title}</Text>
      <Text style={styles.scheduleText}>{formatDate(item.event.starts_at)}</Text>

      <View style={styles.cardFooter}>
        <Text style={styles.refCode}>Ref: {item.public_id}</Text>
        <Text style={styles.showTicketText}>Show QR &rarr;</Text>
      </View>
    </Pressable>
  );

  const renderTransfer = (transfer: TicketTransfer) => (
    <View key={transfer.id} style={styles.transferCard}>
      <Text style={styles.transferTitle}>{transfer.ticket.event.title || 'Event ticket'}</Text>
      <Text style={styles.transferDetail}>
        {transfer.direction === 'incoming'
          ? `${transfer.sender.name} offered you a ticket.`
          : `Waiting for ${transfer.recipient.name} to accept.`}
      </Text>
      <Text style={styles.transferRef}>Ref: {transfer.ticket.public_id}</Text>
      <View style={styles.transferActions}>
        {transfer.direction === 'incoming' ? (
          <>
            <Pressable disabled={busyTransferId === transfer.id} style={styles.transferPrimaryButton} onPress={() => handleTransferAction(transfer, 'accept')}>
              <Text style={styles.transferPrimaryText}>{busyTransferId === transfer.id ? 'Please wait…' : 'Accept'}</Text>
            </Pressable>
            <Pressable disabled={busyTransferId === transfer.id} style={styles.transferSecondaryButton} onPress={() => handleTransferAction(transfer, 'decline')}>
              <Text style={styles.transferSecondaryText}>Decline</Text>
            </Pressable>
          </>
        ) : (
          <Pressable disabled={busyTransferId === transfer.id} style={styles.transferSecondaryButton} onPress={() => handleTransferAction(transfer, 'cancel')}>
            <Text style={styles.transferSecondaryText}>Cancel request</Text>
          </Pressable>
        )}
      </View>
    </View>
  );

  if (isLoading) {
    return (
      <View style={styles.centerContainer}>
        <ActivityIndicator size="large" color="#800000" />
        <Text style={styles.loadingText}>Loading your ticket wallet...</Text>
      </View>
    );
  }

  return (
    <View style={styles.container}>
      {offlineSince && (
        <View style={styles.errorBanner}>
          <Text style={styles.errorText}>Offline copy saved {new Date(offlineSince).toLocaleString()}. Ticket status may have changed; event staff will verify it.</Text>
        </View>
      )}
      {!offlineSince && cacheUnavailable && !errorMessage && (
        <View style={styles.errorBanner}><Text style={styles.errorText}>Offline storage is unavailable. Connect to view these tickets later.</Text></View>
      )}
      {errorMessage && (
        <View style={styles.errorBanner}>
          <Text style={styles.errorText}>{errorMessage}</Text>
        </View>
      )}

      <FlatList
        data={tickets}
        keyExtractor={(item) => item.id.toString()}
        renderItem={renderTicketCard}
        ListHeaderComponent={(
          <View>
            {transferMessage && <Text style={styles.transferMessage}>{transferMessage}</Text>}
            {transfers.length > 0 && (
              <View style={styles.transferSection}>
                <Text style={styles.transferSectionTitle}>Ticket transfers</Text>
                {transfers.map(renderTransfer)}
              </View>
            )}
          </View>
        )}
        contentContainerStyle={styles.listContainer}
        refreshControl={
          <RefreshControl
            refreshing={isRefreshing}
            onRefresh={() => {
              setIsRefreshing(true);
              fetchTickets();
            }}
            colors={['#800000']}
          />
        }
        ListEmptyComponent={!errorMessage ? (
          <View style={styles.emptyContainer}>
            <Text style={styles.emptyTitle}>No Active Tickets</Text>
            <Text style={styles.emptySubtitle}>
              You have not registered for any upcoming events. Explore campus events in the Discover tab.
            </Text>
            <Pressable
              style={styles.discoverButton}
              onPress={() => router.push('/(attendee)/events')}
            >
              <Text style={styles.discoverButtonText}>Discover Events</Text>
            </Pressable>
          </View>
        ) : undefined}
      />
    </View>
  );
}

const styles = StyleSheet.create({
  container: {
    flex: 1,
    backgroundColor: '#f8fafc',
  },
  listContainer: {
    padding: 16,
  },
  centerContainer: {
    flex: 1,
    justifyContent: 'center',
    alignItems: 'center',
    backgroundColor: '#f8fafc',
  },
  loadingText: {
    marginTop: 12,
    fontSize: 13,
    color: '#64748b',
  },
  errorBanner: {
    backgroundColor: '#fee2e2',
    borderLeftWidth: 4,
    borderLeftColor: '#dc2626',
    margin: 16,
    padding: 12,
    borderRadius: 4,
  },
  errorText: {
    color: '#991b1b',
    fontSize: 13,
  },
  ticketCard: {
    backgroundColor: '#ffffff',
    borderRadius: 8,
    borderWidth: 1,
    borderColor: '#e2e8f0',
    borderTopWidth: 4,
    borderTopColor: '#c5a059',
    padding: 16,
    marginBottom: 12,
  },
  cardPressed: {
    backgroundColor: '#f8fafc',
  },
  cardHeader: {
    flexDirection: 'row',
    justifyContent: 'space-between',
    alignItems: 'center',
    marginBottom: 8,
  },
  venueText: {
    fontSize: 11,
    fontWeight: '700',
    color: '#800000',
    textTransform: 'uppercase',
  },
  activeBadge: {
    backgroundColor: '#dcfce7',
    paddingHorizontal: 8,
    paddingVertical: 2,
    borderRadius: 4,
  },
  activeBadgeText: {
    color: '#166534',
    fontSize: 10,
    fontWeight: '700',
  },
  revokedBadge: {
    backgroundColor: '#fee2e2',
    paddingHorizontal: 8,
    paddingVertical: 2,
    borderRadius: 4,
  },
  revokedBadgeText: {
    color: '#991b1b',
    fontSize: 10,
    fontWeight: '700',
  },
  eventTitle: {
    fontSize: 16,
    fontWeight: '700',
    color: '#0f172a',
    marginBottom: 4,
  },
  scheduleText: {
    fontSize: 13,
    color: '#475569',
    marginBottom: 12,
  },
  cardFooter: {
    flexDirection: 'row',
    justifyContent: 'space-between',
    alignItems: 'center',
    borderTopWidth: 1,
    borderTopColor: '#f1f5f9',
    paddingTop: 10,
  },
  refCode: {
    fontSize: 12,
    fontFamily: 'monospace',
    color: '#64748b',
  },
  showTicketText: {
    color: '#800000',
    fontSize: 13,
    fontWeight: '700',
  },
  transferSection: {
    marginBottom: 16,
  },
  transferSectionTitle: {
    color: '#0f172a',
    fontSize: 18,
    fontWeight: '700',
    marginBottom: 10,
  },
  transferCard: {
    backgroundColor: '#fff7ed',
    borderColor: '#fed7aa',
    borderWidth: 1,
    borderRadius: 8,
    padding: 12,
    marginBottom: 10,
  },
  transferTitle: {
    color: '#0f172a',
    fontSize: 15,
    fontWeight: '700',
  },
  transferDetail: {
    color: '#475569',
    fontSize: 13,
    marginTop: 4,
  },
  transferRef: {
    color: '#64748b',
    fontSize: 11,
    fontFamily: 'monospace',
    marginTop: 4,
  },
  transferActions: {
    flexDirection: 'row',
    gap: 8,
    marginTop: 10,
  },
  transferPrimaryButton: {
    backgroundColor: '#800000',
    borderRadius: 5,
    paddingHorizontal: 14,
    paddingVertical: 8,
  },
  transferPrimaryText: {
    color: '#fff',
    fontSize: 12,
    fontWeight: '700',
  },
  transferSecondaryButton: {
    backgroundColor: '#fff',
    borderColor: '#cbd5e1',
    borderWidth: 1,
    borderRadius: 5,
    paddingHorizontal: 14,
    paddingVertical: 8,
  },
  transferSecondaryText: {
    color: '#334155',
    fontSize: 12,
    fontWeight: '600',
  },
  transferMessage: {
    color: '#166534',
    fontSize: 13,
    marginBottom: 10,
  },
  emptyContainer: {
    padding: 40,
    alignItems: 'center',
  },
  emptyTitle: {
    fontSize: 16,
    fontWeight: '700',
    color: '#0f172a',
    marginBottom: 6,
  },
  emptySubtitle: {
    fontSize: 13,
    color: '#64748b',
    textAlign: 'center',
    lineHeight: 18,
    marginBottom: 16,
  },
  discoverButton: {
    backgroundColor: '#800000',
    paddingHorizontal: 20,
    paddingVertical: 10,
    borderRadius: 6,
  },
  discoverButtonText: {
    color: '#ffffff',
    fontSize: 13,
    fontWeight: '600',
  },
});

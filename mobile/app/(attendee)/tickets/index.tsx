import { router } from 'expo-router';
import React, { useEffect, useState } from 'react';
import {
  ActivityIndicator,
  FlatList,
  Pressable,
  RefreshControl,
  StyleSheet,
  Text,
  View,
} from 'react-native';
import { apiRequest } from '../../../src/services/api-client';

export interface TicketItem {
  id: number;
  public_id: string;
  status: string;
  issued_at: string;
  event: {
    id: number;
    title: string;
    venue?: {
      name: string;
      location: string;
    };
    starts_at: string;
    ends_at: string;
  };
}

export default function MyTicketsScreen() {
  const [tickets, setTickets] = useState<TicketItem[]>([]);
  const [isLoading, setIsLoading] = useState(true);
  const [isRefreshing, setIsRefreshing] = useState(false);
  const [errorMessage, setErrorMessage] = useState<string | null>(null);

  const fetchTickets = async () => {
    try {
      setErrorMessage(null);
      const res = await apiRequest<{ data: TicketItem[] }>('/me/tickets');
      setTickets(res.data || []);
    } catch (err: unknown) {
      const errorObj = err as { message?: string };
      setErrorMessage(errorObj?.message || 'Could not load your digital tickets.');
    } finally {
      setIsLoading(false);
      setIsRefreshing(false);
    }
  };

  useEffect(() => {
    fetchTickets();
  }, []);

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
        <View style={item.status === 'active' ? styles.activeBadge : styles.revokedBadge}>
          <Text style={item.status === 'active' ? styles.activeBadgeText : styles.revokedBadgeText}>
            {item.status.toUpperCase()}
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
      {errorMessage && (
        <View style={styles.errorBanner}>
          <Text style={styles.errorText}>{errorMessage}</Text>
        </View>
      )}

      <FlatList
        data={tickets}
        keyExtractor={(item) => item.id.toString()}
        renderItem={renderTicketCard}
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
        ListEmptyComponent={
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
        }
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

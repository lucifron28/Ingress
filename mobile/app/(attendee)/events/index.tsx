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

export interface EventItem {
  id: number;
  title: string;
  slug: string;
  description: string | null;
  venue?: {
    id: number;
    name: string;
    code: string;
    location: string;
  };
  capacity: number;
  confirmed_registrations_count: number;
  remaining_capacity: number;
  is_registration_open: boolean;
  registration_opens_at: string;
  registration_closes_at: string;
  starts_at: string;
  ends_at: string;
  status: string;
  is_user_registered?: boolean;
}

export default function DiscoverEventsScreen() {
  const [events, setEvents] = useState<EventItem[]>([]);
  const [isLoading, setIsLoading] = useState(true);
  const [isRefreshing, setIsRefreshing] = useState(false);
  const [errorMessage, setErrorMessage] = useState<string | null>(null);

  const fetchEvents = async () => {
    try {
      setErrorMessage(null);
      const res = await apiRequest<{ data: EventItem[] }>('/events');
      setEvents(res.data || []);
    } catch (err: unknown) {
      const errorObj = err as { message?: string };
      setErrorMessage(errorObj?.message || 'Could not load events from server.');
    } finally {
      setIsLoading(false);
      setIsRefreshing(false);
    }
  };

  useEffect(() => {
    fetchEvents();
  }, []);

  const handleRefresh = () => {
    setIsRefreshing(true);
    fetchEvents();
  };

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

  const renderEventCard = ({ item }: { item: EventItem }) => {
    return (
      <Pressable
        style={({ pressed }) => [styles.card, pressed && styles.cardPressed]}
        onPress={() => router.push(`/(attendee)/events/${item.id}`)}
      >
        <View style={styles.cardHeader}>
          <Text style={styles.venueBadge}>{item.venue?.name || 'MSEUF Campus'}</Text>
          {item.is_registration_open ? (
            <View style={styles.openBadge}>
              <Text style={styles.openBadgeText}>Registration Open</Text>
            </View>
          ) : (
            <View style={styles.closedBadge}>
              <Text style={styles.closedBadgeText}>Closed</Text>
            </View>
          )}
        </View>

        <Text style={styles.eventTitle}>{item.title}</Text>

        <Text style={styles.scheduleText}>Date: {formatDate(item.starts_at)}</Text>

        <View style={styles.cardFooter}>
          <View style={styles.seatBadge}>
            <Text style={styles.seatText}>
              {item.remaining_capacity} {item.remaining_capacity === 1 ? 'seat' : 'seats'} remaining
            </Text>
          </View>
          <Text style={styles.viewDetailsText}>View Details &rarr;</Text>
        </View>
      </Pressable>
    );
  };

  if (isLoading) {
    return (
      <View style={styles.centerContainer}>
        <ActivityIndicator size="large" color="#800000" />
        <Text style={styles.loadingText}>Loading campus events...</Text>
      </View>
    );
  }

  return (
    <View style={styles.container}>
      {errorMessage && (
        <View style={styles.errorBanner}>
          <Text style={styles.errorText}>{errorMessage}</Text>
          <Pressable onPress={fetchEvents} style={styles.retryButton}>
            <Text style={styles.retryButtonText}>Retry</Text>
          </Pressable>
        </View>
      )}

      <FlatList
        data={events}
        keyExtractor={(item) => item.id.toString()}
        renderItem={renderEventCard}
        contentContainerStyle={styles.listContainer}
        refreshControl={
          <RefreshControl
            refreshing={isRefreshing}
            onRefresh={handleRefresh}
            colors={['#800000']}
          />
        }
        ListEmptyComponent={
          <View style={styles.emptyContainer}>
            <Text style={styles.emptyTitle}>No Upcoming Events</Text>
            <Text style={styles.emptySubtitle}>
              Check back later for university lectures, symposiums, and campus activities.
            </Text>
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
    marginBottom: 0,
    padding: 12,
    borderRadius: 4,
    flexDirection: 'row',
    justifyContent: 'space-between',
    alignItems: 'center',
  },
  errorText: {
    color: '#991b1b',
    fontSize: 13,
    flex: 1,
  },
  retryButton: {
    backgroundColor: '#dc2626',
    paddingHorizontal: 12,
    paddingVertical: 6,
    borderRadius: 4,
    marginLeft: 8,
  },
  retryButtonText: {
    color: '#ffffff',
    fontSize: 12,
    fontWeight: '600',
  },
  card: {
    backgroundColor: '#ffffff',
    borderRadius: 8,
    borderWidth: 1,
    borderColor: '#e2e8f0',
    borderLeftWidth: 4,
    borderLeftColor: '#800000',
    padding: 16,
    marginBottom: 12,
    shadowColor: '#000',
    shadowOffset: { width: 0, height: 1 },
    shadowOpacity: 0.05,
    shadowRadius: 2,
    elevation: 1,
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
  venueBadge: {
    fontSize: 11,
    fontWeight: '700',
    color: '#800000',
    textTransform: 'uppercase',
    letterSpacing: 0.5,
  },
  openBadge: {
    backgroundColor: '#dcfce7',
    paddingHorizontal: 8,
    paddingVertical: 2,
    borderRadius: 4,
  },
  openBadgeText: {
    color: '#166534',
    fontSize: 11,
    fontWeight: '600',
  },
  closedBadge: {
    backgroundColor: '#f1f5f9',
    paddingHorizontal: 8,
    paddingVertical: 2,
    borderRadius: 4,
  },
  closedBadgeText: {
    color: '#475569',
    fontSize: 11,
    fontWeight: '600',
  },
  eventTitle: {
    fontSize: 16,
    fontWeight: '700',
    color: '#0f172a',
    marginBottom: 6,
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
  seatBadge: {
    backgroundColor: '#fef3c7',
    paddingHorizontal: 8,
    paddingVertical: 3,
    borderRadius: 4,
  },
  seatText: {
    color: '#92400e',
    fontSize: 12,
    fontWeight: '600',
  },
  viewDetailsText: {
    color: '#800000',
    fontSize: 13,
    fontWeight: '600',
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
  },
});

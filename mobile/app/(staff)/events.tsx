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
import { useAuth } from '../../src/context/AuthContext';
import { useNodeStaff } from '../../src/context/NodeStaffContext';
import { nodeRequest } from '../../src/services/node-session';
import { apiRequest } from '../../src/services/api-client';

interface AssignedEvent {
  id: number;
  title: string;
  venue?: {
    name: string;
    location: string;
  };
  starts_at: string;
  ends_at: string;
  access_points: Array<{
    id: number;
    name: string;
    code: string;
  }>;
}

export default function StaffEventsScreen() {
  const cloud = useAuth();
  const node = useNodeStaff();
  const user = node.active ? node.session?.user : cloud.user;
  const request = node.active ? nodeRequest : apiRequest;
  const [events, setEvents] = useState<AssignedEvent[]>([]);
  const [isLoading, setIsLoading] = useState(true);
  const [isRefreshing, setIsRefreshing] = useState(false);
  const [errorMessage, setErrorMessage] = useState<string | null>(null);

  const fetchAssignedEvents = async () => {
    try {
      setErrorMessage(null);
      const res = await request<{ events: AssignedEvent[] }>('/staff/events');
      setEvents(res.events || []);
    } catch (err: unknown) {
      const errorObj = err as { message?: string };
      setErrorMessage(errorObj?.message || 'Could not load assigned events.');
    } finally {
      setIsLoading(false);
      setIsRefreshing(false);
    }
  };

  useEffect(() => {
    fetchAssignedEvents();
  }, []);

  const handleSelectGate = (event: AssignedEvent, gateId: number, gateName: string) => {
    router.push({
      pathname: '/(staff)/scan',
      params: {
        eventId: event.id.toString(),
        eventTitle: event.title,
        accessPointId: gateId.toString(),
        accessPointName: gateName,
      },
    });
  };

  const renderEventCard = ({ item }: { item: AssignedEvent }) => (
    <View style={styles.card}>
      <Text style={styles.venueBadge}>{item.venue?.name || 'MSEUF Campus'}</Text>
      <Text style={styles.title}>{item.title}</Text>

      <View style={styles.gateSection}>
        <Text style={styles.gateHeading}>Select Gate to Begin Scanning</Text>
        <View style={styles.gateList}>
          {item.access_points && item.access_points.length > 0 ? (
            item.access_points.map((gate) => (
              <Pressable
                key={gate.id}
                style={({ pressed }) => [styles.gateButton, pressed && styles.gateButtonPressed]}
                onPress={() => handleSelectGate(item, gate.id, gate.name)}
              >
                <View style={styles.gateInfo}>
                  <Text style={styles.gateCode}>{gate.code}</Text>
                  <Text style={styles.gateName}>{gate.name}</Text>
                </View>
                <Text style={styles.scanArrow}>Scan &rarr;</Text>
              </Pressable>
            ))
          ) : (
            <Text style={styles.noGatesText}>No active access points configured for this event.</Text>
          )}
        </View>
      </View>
    </View>
  );

  if (isLoading) {
    return (
      <View style={styles.centerContainer}>
        <ActivityIndicator size="large" color="#800000" />
        <Text style={styles.loadingText}>Loading assigned events...</Text>
      </View>
    );
  }

  return (
    <View style={styles.container}>
      <View style={styles.staffHeader}>
        <Text style={styles.greeting}>Welcome, {user?.name}</Text>
        <Text style={styles.instructions}>
          Select your assigned entrance gate to validate attendee QR tickets against the local Ingress server.
        </Text>
      </View>

      {errorMessage && (
        <View style={styles.errorBanner}>
          <Text style={styles.errorText}>{errorMessage}</Text>
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
            onRefresh={() => {
              setIsRefreshing(true);
              fetchAssignedEvents();
            }}
            colors={['#800000']}
          />
        }
        ListEmptyComponent={
          <View style={styles.emptyContainer}>
            <Text style={styles.emptyTitle}>No Events Assigned</Text>
            <Text style={styles.emptySubtitle}>
              You have not been assigned to scan tickets for any active events. Contact the event organizer.
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
  staffHeader: {
    backgroundColor: '#ffffff',
    padding: 16,
    borderBottomWidth: 1,
    borderBottomColor: '#e2e8f0',
  },
  greeting: {
    fontSize: 16,
    fontWeight: '800',
    color: '#0f172a',
    marginBottom: 4,
  },
  instructions: {
    fontSize: 13,
    color: '#64748b',
    lineHeight: 18,
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
  card: {
    backgroundColor: '#ffffff',
    borderRadius: 8,
    borderWidth: 1,
    borderColor: '#e2e8f0',
    borderLeftWidth: 4,
    borderLeftColor: '#800000',
    padding: 16,
    marginBottom: 14,
    shadowColor: '#000',
    shadowOffset: { width: 0, height: 1 },
    shadowOpacity: 0.05,
    shadowRadius: 2,
    elevation: 1,
  },
  venueBadge: {
    fontSize: 11,
    fontWeight: '700',
    color: '#800000',
    textTransform: 'uppercase',
    marginBottom: 4,
  },
  title: {
    fontSize: 17,
    fontWeight: '700',
    color: '#0f172a',
    marginBottom: 12,
  },
  gateSection: {
    borderTopWidth: 1,
    borderTopColor: '#f1f5f9',
    paddingTop: 12,
  },
  gateHeading: {
    fontSize: 11,
    fontWeight: '700',
    textTransform: 'uppercase',
    color: '#94a3b8',
    letterSpacing: 0.5,
    marginBottom: 8,
  },
  gateList: {
    gap: 8,
  },
  gateButton: {
    flexDirection: 'row',
    justifyContent: 'space-between',
    alignItems: 'center',
    backgroundColor: '#f8fafc',
    borderWidth: 1,
    borderColor: '#cbd5e1',
    borderRadius: 6,
    paddingVertical: 10,
    paddingHorizontal: 12,
  },
  gateButtonPressed: {
    backgroundColor: '#f1f5f9',
  },
  gateInfo: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: 10,
  },
  gateCode: {
    fontSize: 11,
    fontFamily: 'monospace',
    fontWeight: '700',
    backgroundColor: '#e2e8f0',
    color: '#1e293b',
    paddingHorizontal: 6,
    paddingVertical: 2,
    borderRadius: 3,
  },
  gateName: {
    fontSize: 13,
    fontWeight: '600',
    color: '#0f172a',
  },
  scanArrow: {
    fontSize: 13,
    fontWeight: '700',
    color: '#800000',
  },
  noGatesText: {
    fontSize: 12,
    color: '#94a3b8',
    fontStyle: 'italic',
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

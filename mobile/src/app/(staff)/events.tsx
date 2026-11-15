import { Redirect, router } from 'expo-router';
import React, { useCallback, useEffect, useRef, useState } from 'react';
import {
  ActivityIndicator,
  FlatList,
  Pressable,
  RefreshControl,
  StyleSheet,
  Text,
  View,
} from 'react-native';
import { useNodeStaff } from '../../context/NodeStaffContext';
import { nodeRequest } from '../../services/node-session';

interface AssignedEvent {
  id: number;
  title: string;
  venue?: {
    name: string;
    location: string;
  };
  starts_at: string;
  ends_at: string;
  access_points: {
    id: number;
    name: string;
    code: string;
  }[];
}

export default function StaffEventsScreen() {
  const node = useNodeStaff();
  if (!node.active || !node.session) return <Redirect href="/node-staff-login" />;
  const sessionIdentity = `${node.session.url}:${node.session.user.id}`;

  return <AssignedEvents key={sessionIdentity} userName={node.session.user.name} />;
}

function AssignedEvents({ userName }: { userName: string }) {
  const requestGeneration = useRef(0);
  const mounted = useRef(true);
  const [events, setEvents] = useState<AssignedEvent[]>([]);
  const [isLoading, setIsLoading] = useState(true);
  const [isRefreshing, setIsRefreshing] = useState(false);
  const [errorMessage, setErrorMessage] = useState<string | null>(null);

  const fetchAssignedEvents = useCallback(async () => {
    const generation = ++requestGeneration.current;
    const isCurrent = () => mounted.current && generation === requestGeneration.current;
    try {
      const res = await nodeRequest<{ events: AssignedEvent[] }>('/staff/events');
      if (!isCurrent()) return;
      setErrorMessage(null);
      setEvents(res.events || []);
    } catch (err: unknown) {
      if (!isCurrent()) return;
      const errorObj = err as { message?: string };
      setErrorMessage(errorObj?.message || 'Could not load assigned events.');
    } finally {
      if (isCurrent()) {
        setIsLoading(false);
        setIsRefreshing(false);
      }
    }
  }, []);

  useEffect(() => {
    mounted.current = true;
    // nodeRequest awaits SecureStore and the Event Node before the fetch can update state.
    // eslint-disable-next-line react-hooks/set-state-in-effect
    void fetchAssignedEvents();
    return () => { mounted.current = false; };
  }, [fetchAssignedEvents]);

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

      <Pressable style={styles.walkInButton} onPress={() => router.push({
        pathname: '/(staff)/walk-in', params: { eventId: item.id.toString(), eventTitle: item.title },
      })}>
        <Text style={styles.walkInText}>Register walk-in</Text>
      </Pressable>

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
        <Text style={styles.greeting}>Welcome, {userName}</Text>
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
              void fetchAssignedEvents();
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
  walkInButton: {
    backgroundColor: '#800000', borderRadius: 6, padding: 12, alignItems: 'center', marginBottom: 12,
  },
  walkInText: { color: '#ffffff', fontWeight: '700', fontSize: 14 },
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

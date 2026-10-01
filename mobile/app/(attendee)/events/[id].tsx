import { router, useLocalSearchParams } from 'expo-router';
import React, { useEffect, useState } from 'react';
import {
  ActivityIndicator,
  Alert,
  Pressable,
  ScrollView,
  StyleSheet,
  Text,
  View,
} from 'react-native';
import { useAuth } from '../../../src/context/AuthContext';
import { apiRequest } from '../../../src/services/api-client';
import { EventItem } from './index';

interface EventDetailResponse {
  event: EventItem & {
    user_ticket?: {
      id: number;
      public_id: string;
      status: string;
    } | null;
  };
}

export default function EventDetailScreen() {
  const { id } = useLocalSearchParams<{ id: string }>();
  const { user } = useAuth();
  const [event, setEvent] = useState<EventDetailResponse['event'] | null>(null);
  const [isLoading, setIsLoading] = useState(true);
  const [isRegistering, setIsRegistering] = useState(false);
  const [errorMessage, setErrorMessage] = useState<string | null>(null);

  const fetchEventDetail = async () => {
    try {
      setErrorMessage(null);
      const res = await apiRequest<EventDetailResponse>(`/events/${id}`);
      setEvent(res.event);
    } catch (err: unknown) {
      const errorObj = err as { message?: string };
      setErrorMessage(errorObj?.message || 'Could not load event details.');
    } finally {
      setIsLoading(false);
    }
  };

  useEffect(() => {
    if (id) {
      fetchEventDetail();
    }
  }, [id]);

  const handleRegister = async () => {
    if (!event) return;

    setIsRegistering(true);
    try {
      const res = await apiRequest<{ message: string; ticket: { id: number; public_id: string } }>(
        `/events/${event.id}/registration`,
        { method: 'POST' }
      );

      Alert.alert(
        'Registration Confirmed',
        'Your ticket has been issued! You can now present your digital QR ticket at event entry.',
        [
          {
            text: 'View Ticket',
            onPress: () => router.push(`/(attendee)/tickets/${res.ticket.id}`),
          },
          {
            text: 'OK',
            onPress: () => fetchEventDetail(),
          },
        ]
      );
    } catch (err: unknown) {
      const errorObj = err as { message?: string };
      Alert.alert('Registration Failed', errorObj?.message || 'Unable to register for event.');
    } finally {
      setIsRegistering(false);
    }
  };

  const formatDate = (isoString?: string) => {
    if (!isoString) return 'TBA';
    try {
      const date = new Date(isoString);
      return date.toLocaleDateString('en-US', {
        weekday: 'short',
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

  if (isLoading) {
    return (
      <View style={styles.centerContainer}>
        <ActivityIndicator size="large" color="#800000" />
        <Text style={styles.loadingText}>Loading event details...</Text>
      </View>
    );
  }

  if (errorMessage || !event) {
    return (
      <View style={styles.centerContainer}>
        <Text style={styles.errorText}>{errorMessage || 'Event not found.'}</Text>
        <Pressable style={styles.backButton} onPress={() => router.back()}>
          <Text style={styles.backButtonText}>Go Back</Text>
        </Pressable>
      </View>
    );
  }

  return (
    <ScrollView style={styles.container} contentContainerStyle={styles.contentContainer}>
      <View style={styles.headerCard}>
        <View style={styles.venueRow}>
          <Text style={styles.venueBadge}>{event.venue?.name || 'MSEUF Campus'}</Text>
          <View style={event.is_registration_open ? styles.openBadge : styles.closedBadge}>
            <Text style={event.is_registration_open ? styles.openBadgeText : styles.closedBadgeText}>
              {event.is_registration_open ? 'Registration Open' : 'Registration Closed'}
            </Text>
          </View>
        </View>

        <Text style={styles.title}>{event.title}</Text>
      </View>

      {/* Schedule Info Box */}
      <View style={styles.sectionCard}>
        <Text style={styles.sectionHeading}>Date &amp; Location</Text>
        <View style={styles.infoRow}>
          <Text style={styles.infoLabel}>Starts</Text>
          <Text style={styles.infoValue}>{formatDate(event.starts_at)}</Text>
        </View>
        <View style={styles.infoRow}>
          <Text style={styles.infoLabel}>Ends</Text>
          <Text style={styles.infoValue}>{formatDate(event.ends_at)}</Text>
        </View>
        <View style={styles.infoRow}>
          <Text style={styles.infoLabel}>Venue Location</Text>
          <Text style={styles.infoValue}>{event.venue?.location || 'Main Campus, Lucena City'}</Text>
        </View>
      </View>

      {/* Capacity & Registration Window */}
      <View style={styles.sectionCard}>
        <Text style={styles.sectionHeading}>Capacity &amp; Availability</Text>
        <View style={styles.kpiRow}>
          <View style={styles.kpiBox}>
            <Text style={styles.kpiValue}>{event.capacity}</Text>
            <Text style={styles.kpiLabel}>Total Capacity</Text>
          </View>
          <View style={styles.kpiBox}>
            <Text style={[styles.kpiValue, { color: '#800000' }]}>{event.remaining_capacity}</Text>
            <Text style={styles.kpiLabel}>Seats Remaining</Text>
          </View>
        </View>
        <View style={styles.infoRow}>
          <Text style={styles.infoLabel}>Registration Window</Text>
          <Text style={styles.infoValue}>
            {formatDate(event.registration_opens_at)} - {formatDate(event.registration_closes_at)}
          </Text>
        </View>
      </View>

      {/* Description */}
      <View style={styles.sectionCard}>
        <Text style={styles.sectionHeading}>About This Event</Text>
        <Text style={styles.descriptionText}>
          {event.description || 'No detailed description provided by the organizer.'}
        </Text>
      </View>

      {/* Registration Action Area */}
      <View style={styles.actionCard}>
        {event.is_user_registered ? (
          <View style={styles.registeredBox}>
            <Text style={styles.registeredTitle}>Registration Confirmed</Text>
            <Text style={styles.registeredSubtitle}>
              You have an active digital ticket for this campus event.
            </Text>
            {event.user_ticket && (
              <Pressable
                style={styles.viewTicketButton}
                onPress={() => router.push(`/(attendee)/tickets/${event.user_ticket!.id}`)}
              >
                <Text style={styles.viewTicketButtonText}>Show Digital Ticket</Text>
              </Pressable>
            )}
          </View>
        ) : event.remaining_capacity <= 0 ? (
          <View style={styles.fullBox}>
            <Text style={styles.fullText}>Event Capacity Reached</Text>
            <Text style={styles.fullSubtitle}>All seats for this event are currently reserved.</Text>
          </View>
        ) : !event.is_registration_open ? (
          <View style={styles.closedBox}>
            <Text style={styles.closedText}>Registration Currently Closed</Text>
          </View>
        ) : (
          <Pressable
            style={[styles.registerButton, isRegistering && styles.buttonDisabled]}
            onPress={handleRegister}
            disabled={isRegistering}
          >
            {isRegistering ? (
              <ActivityIndicator color="#ffffff" size="small" />
            ) : (
              <Text style={styles.registerButtonText}>Register for Event</Text>
            )}
          </Pressable>
        )}
      </View>
    </ScrollView>
  );
}

const styles = StyleSheet.create({
  container: {
    flex: 1,
    backgroundColor: '#f8fafc',
  },
  contentContainer: {
    padding: 16,
    paddingBottom: 40,
  },
  centerContainer: {
    flex: 1,
    justifyContent: 'center',
    alignItems: 'center',
    padding: 20,
    backgroundColor: '#f8fafc',
  },
  loadingText: {
    marginTop: 12,
    fontSize: 13,
    color: '#64748b',
  },
  errorText: {
    color: '#991b1b',
    fontSize: 14,
    marginBottom: 16,
    textAlign: 'center',
  },
  backButton: {
    backgroundColor: '#800000',
    paddingHorizontal: 16,
    paddingVertical: 8,
    borderRadius: 4,
  },
  backButtonText: {
    color: '#ffffff',
    fontWeight: '600',
    fontSize: 13,
  },
  headerCard: {
    backgroundColor: '#ffffff',
    borderRadius: 8,
    borderWidth: 1,
    borderColor: '#e2e8f0',
    borderTopWidth: 4,
    borderTopColor: '#800000',
    padding: 16,
    marginBottom: 12,
  },
  venueRow: {
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
  title: {
    fontSize: 20,
    fontWeight: '800',
    color: '#0f172a',
    lineHeight: 26,
  },
  sectionCard: {
    backgroundColor: '#ffffff',
    borderRadius: 8,
    borderWidth: 1,
    borderColor: '#e2e8f0',
    padding: 16,
    marginBottom: 12,
  },
  sectionHeading: {
    fontSize: 13,
    fontWeight: '700',
    textTransform: 'uppercase',
    color: '#94a3b8',
    letterSpacing: 0.5,
    marginBottom: 12,
  },
  infoRow: {
    marginBottom: 10,
  },
  infoLabel: {
    fontSize: 11,
    color: '#64748b',
    fontWeight: '500',
  },
  infoValue: {
    fontSize: 13,
    color: '#0f172a',
    fontWeight: '600',
    marginTop: 2,
  },
  kpiRow: {
    flexDirection: 'row',
    gap: 12,
    marginBottom: 12,
  },
  kpiBox: {
    flex: 1,
    backgroundColor: '#f8fafc',
    borderWidth: 1,
    borderColor: '#e2e8f0',
    borderRadius: 6,
    padding: 12,
    alignItems: 'center',
  },
  kpiValue: {
    fontSize: 22,
    fontWeight: '800',
    color: '#0f172a',
  },
  kpiLabel: {
    fontSize: 11,
    color: '#64748b',
    marginTop: 2,
  },
  descriptionText: {
    fontSize: 14,
    color: '#334155',
    lineHeight: 22,
  },
  actionCard: {
    marginTop: 8,
  },
  registerButton: {
    backgroundColor: '#800000',
    height: 48,
    borderRadius: 6,
    justifyContent: 'center',
    alignItems: 'center',
    borderWidth: 1,
    borderColor: '#5b0000',
  },
  registerButtonText: {
    color: '#ffffff',
    fontSize: 15,
    fontWeight: '700',
  },
  buttonDisabled: {
    opacity: 0.7,
  },
  registeredBox: {
    backgroundColor: '#ecfdf5',
    borderWidth: 1,
    borderColor: '#a7f3d0',
    borderRadius: 6,
    padding: 16,
    alignItems: 'center',
  },
  registeredTitle: {
    color: '#065f46',
    fontSize: 15,
    fontWeight: '700',
    marginBottom: 4,
  },
  registeredSubtitle: {
    color: '#047857',
    fontSize: 12,
    textAlign: 'center',
    marginBottom: 12,
  },
  viewTicketButton: {
    backgroundColor: '#16a34a',
    paddingHorizontal: 20,
    paddingVertical: 10,
    borderRadius: 6,
  },
  viewTicketButtonText: {
    color: '#ffffff',
    fontSize: 13,
    fontWeight: '700',
  },
  fullBox: {
    backgroundColor: '#fef3c7',
    borderWidth: 1,
    borderColor: '#fde68a',
    borderRadius: 6,
    padding: 16,
    alignItems: 'center',
  },
  fullText: {
    color: '#92400e',
    fontSize: 14,
    fontWeight: '700',
  },
  fullSubtitle: {
    color: '#b45309',
    fontSize: 12,
    marginTop: 4,
  },
  closedBox: {
    backgroundColor: '#f1f5f9',
    borderRadius: 6,
    padding: 16,
    alignItems: 'center',
  },
  closedText: {
    color: '#475569',
    fontSize: 13,
    fontWeight: '600',
  },
});

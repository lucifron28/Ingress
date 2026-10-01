import { router, useLocalSearchParams } from 'expo-router';
import React, { useEffect, useState } from 'react';
import {
  ActivityIndicator,
  Pressable,
  ScrollView,
  StyleSheet,
  Text,
  View,
} from 'react-native';
import QRCode from 'react-native-qrcode-svg';
import { apiRequest } from '../../../src/services/api-client';

interface TicketDetail {
  id: number;
  public_id: string;
  status: string;
  issued_at: string;
  qr_payload: string; // Opaque payload: ingress:v1:<public_id>:<secret>
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
  attendee: {
    name: string;
    student_id: string | null;
    email: string;
  };
}

export default function TicketDetailScreen() {
  const { id } = useLocalSearchParams<{ id: string }>();
  const [ticket, setTicket] = useState<TicketDetail | null>(null);
  const [isLoading, setIsLoading] = useState(true);
  const [errorMessage, setErrorMessage] = useState<string | null>(null);

  const fetchTicket = async () => {
    try {
      setErrorMessage(null);
      const res = await apiRequest<{ ticket: TicketDetail }>(`/tickets/${id}`);
      setTicket(res.ticket);
    } catch (err: unknown) {
      const errorObj = err as { message?: string };
      setErrorMessage(errorObj?.message || 'Could not load digital ticket.');
    } finally {
      setIsLoading(false);
    }
  };

  useEffect(() => {
    if (id) {
      fetchTicket();
    }
  }, [id]);

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
        <Text style={styles.loadingText}>Loading digital ticket...</Text>
      </View>
    );
  }

  if (errorMessage || !ticket) {
    return (
      <View style={styles.centerContainer}>
        <Text style={styles.errorText}>{errorMessage || 'Ticket not found.'}</Text>
        <Pressable style={styles.backButton} onPress={() => router.back()}>
          <Text style={styles.backButtonText}>Back to Tickets</Text>
        </Pressable>
      </View>
    );
  }

  return (
    <ScrollView style={styles.container} contentContainerStyle={styles.contentContainer}>
      <View style={styles.ticketCanvas}>
        {/* Ticket Header */}
        <View style={styles.ticketHeader}>
          <View style={styles.badgeRow}>
            <View style={styles.mseufBadge}>
              <Text style={styles.mseufBadgeText}>MSEUF INGRESS</Text>
            </View>
            <View style={ticket.status === 'active' ? styles.statusActive : styles.statusRevoked}>
              <Text style={ticket.status === 'active' ? styles.statusActiveText : styles.statusRevokedText}>
                {ticket.status.toUpperCase()}
              </Text>
            </View>
          </View>
          <Text style={styles.eventTitle}>{ticket.event.title}</Text>
          <Text style={styles.venueText}>{ticket.event.venue?.name || 'MSEUF Campus'}</Text>
        </View>

        {/* High-Contrast Opaque QR Canvas */}
        <View style={styles.qrSection}>
          <View style={styles.qrContainer}>
            <QRCode
              value={ticket.qr_payload || `ingress:v1:${ticket.public_id}`}
              size={220}
              color="#000000"
              backgroundColor="#ffffff"
            />
          </View>

          <Text style={styles.refCode}>Ticket Ref: {ticket.public_id}</Text>
          <Text style={styles.securityNotice}>
            Opaque Cryptographic Credential &bull; Zero Student PII Encoded
          </Text>
        </View>

        {/* Attendee & Schedule Meta */}
        <View style={styles.metaSection}>
          <View style={styles.metaRow}>
            <View style={styles.metaCol}>
              <Text style={styles.metaLabel}>Attendee Name</Text>
              <Text style={styles.metaValue}>{ticket.attendee.name}</Text>
            </View>
            <View style={styles.metaCol}>
              <Text style={styles.metaLabel}>Student ID</Text>
              <Text style={[styles.metaValue, { fontFamily: 'monospace' }]}>
                {ticket.attendee.student_id || 'N/A'}
              </Text>
            </View>
          </View>

          <View style={styles.metaRow}>
            <View style={styles.metaCol}>
              <Text style={styles.metaLabel}>Event Schedule</Text>
              <Text style={styles.metaValue}>{formatDate(ticket.event.starts_at)}</Text>
            </View>
            <View style={styles.metaCol}>
              <Text style={styles.metaLabel}>Venue Location</Text>
              <Text style={styles.metaValue}>{ticket.event.venue?.location || 'Lucena City'}</Text>
            </View>
          </View>
        </View>

        {/* Turnstile Instructions */}
        <View style={styles.footerNote}>
          <Text style={styles.instructionTitle}>Gate Admission Notice</Text>
          <Text style={styles.instructionText}>
            Present this QR code to the entrance staff scanner at Gate A, Gate B, or the Main Entrance.
            Each ticket is valid for one admission only. Duplicate scans are instantly flagged.
          </Text>
        </View>
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
    padding: 20,
    alignItems: 'center',
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
  ticketCanvas: {
    width: '100%',
    maxWidth: 400,
    backgroundColor: '#ffffff',
    borderRadius: 12,
    borderWidth: 1,
    borderColor: '#e2e8f0',
    overflow: 'hidden',
    shadowColor: '#000',
    shadowOffset: { width: 0, height: 4 },
    shadowOpacity: 0.08,
    shadowRadius: 8,
    elevation: 3,
  },
  ticketHeader: {
    backgroundColor: '#800000',
    borderBottomWidth: 3,
    borderBottomColor: '#c5a059',
    padding: 18,
  },
  badgeRow: {
    flexDirection: 'row',
    justifyContent: 'space-between',
    alignItems: 'center',
    marginBottom: 10,
  },
  mseufBadge: {
    backgroundColor: '#ffffff',
    paddingHorizontal: 8,
    paddingVertical: 2,
    borderRadius: 3,
  },
  mseufBadgeText: {
    color: '#800000',
    fontSize: 11,
    fontWeight: '800',
    letterSpacing: 0.5,
  },
  statusActive: {
    backgroundColor: '#dcfce7',
    paddingHorizontal: 8,
    paddingVertical: 2,
    borderRadius: 4,
  },
  statusActiveText: {
    color: '#166534',
    fontSize: 10,
    fontWeight: '800',
  },
  statusRevoked: {
    backgroundColor: '#fee2e2',
    paddingHorizontal: 8,
    paddingVertical: 2,
    borderRadius: 4,
  },
  statusRevokedText: {
    color: '#991b1b',
    fontSize: 10,
    fontWeight: '800',
  },
  eventTitle: {
    color: '#ffffff',
    fontSize: 18,
    fontWeight: '800',
    marginBottom: 4,
    lineHeight: 24,
  },
  venueText: {
    color: '#fecaca',
    fontSize: 13,
    fontWeight: '500',
  },
  qrSection: {
    alignItems: 'center',
    padding: 24,
    backgroundColor: '#ffffff',
    borderBottomWidth: 1,
    borderBottomColor: '#f1f5f9',
  },
  qrContainer: {
    padding: 12,
    backgroundColor: '#ffffff',
    borderRadius: 8,
    borderWidth: 1,
    borderColor: '#e2e8f0',
    marginBottom: 16,
  },
  refCode: {
    fontSize: 13,
    fontFamily: 'monospace',
    fontWeight: '700',
    color: '#0f172a',
    letterSpacing: 0.5,
    marginBottom: 4,
  },
  securityNotice: {
    fontSize: 10,
    color: '#94a3b8',
    textAlign: 'center',
  },
  metaSection: {
    padding: 18,
    backgroundColor: '#f8fafc',
    borderBottomWidth: 1,
    borderBottomColor: '#e2e8f0',
  },
  metaRow: {
    flexDirection: 'row',
    justifyContent: 'space-between',
    marginBottom: 12,
  },
  metaCol: {
    flex: 1,
  },
  metaLabel: {
    fontSize: 11,
    color: '#64748b',
    fontWeight: '600',
    textTransform: 'uppercase',
    marginBottom: 2,
  },
  metaValue: {
    fontSize: 13,
    fontWeight: '600',
    color: '#0f172a',
  },
  footerNote: {
    padding: 16,
    backgroundColor: '#ffffff',
  },
  instructionTitle: {
    fontSize: 11,
    fontWeight: '700',
    textTransform: 'uppercase',
    color: '#64748b',
    marginBottom: 4,
  },
  instructionText: {
    fontSize: 11,
    color: '#94a3b8',
    lineHeight: 16,
  },
});

import { CameraView, useCameraPermissions } from 'expo-camera';
import { router, useLocalSearchParams } from 'expo-router';
import React, { useRef, useState } from 'react';
import {
  ActivityIndicator,
  Modal,
  Platform,
  Pressable,
  StyleSheet,
  Text,
  TextInput,
  View,
} from 'react-native';
import { apiRequest } from '../../src/services/api-client';

interface ScanFeedback {
  type: 'valid' | 'duplicate' | 'invalid' | 'unavailable';
  title: string;
  detail: string;
  attendeeName?: string;
  studentId?: string;
  accessPoint?: string;
  previousAdmittedAt?: string;
}

function generateUuid(): string {
  return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, (c) => {
    const r = (Math.random() * 16) | 0;
    const v = c === 'x' ? r : (r & 0x3) | 0x8;
    return v.toString(16);
  });
}

export default function ScannerScreen() {
  const { eventId, eventTitle, accessPointId, accessPointName } = useLocalSearchParams<{
    eventId: string;
    eventTitle: string;
    accessPointId: string;
    accessPointName: string;
  }>();

  const [permission, requestPermission] = useCameraPermissions();
  const [torchEnabled, setTorchEnabled] = useState(false);
  const [isProcessing, setIsProcessing] = useState(false);
  const [feedback, setFeedback] = useState<ScanFeedback | null>(null);
  const [isManualModalVisible, setIsManualModalVisible] = useState(false);
  const [manualCode, setManualCode] = useState('');
  const isCooldownRef = useRef(false);

  const processScanPayload = async (rawPayload: string) => {
    if (isCooldownRef.current || isProcessing) return;

    isCooldownRef.current = true;
    setIsProcessing(true);
    setFeedback(null);

    const clientScanId = generateUuid();

    try {
      const res = await apiRequest<{
        status: string;
        message: string;
        detail: string;
        admission?: { access_point: string; admitted_at: string; scanned_by: string };
        previous_admission?: { access_point: string; admitted_at: string; scanned_by: string };
        ticket?: { public_id: string; attendee_name: string; student_id: string };
      }>(`/staff/events/${eventId}/admissions`, {
        method: 'POST',
        body: JSON.stringify({
          access_point_id: parseInt(accessPointId || '0', 10),
          qr_payload: rawPayload.trim(),
          client_scan_id: clientScanId,
        }),
      });

      if (res.status === 'admitted') {
        setFeedback({
          type: 'valid',
          title: 'Ticket Valid',
          detail: res.detail,
          attendeeName: res.ticket?.attendee_name,
          studentId: res.ticket?.student_id,
          accessPoint: res.admission?.access_point || accessPointName,
        });
      }
    } catch (err: unknown) {
      const errorObj = err as {
        status?: number;
        message?: string;
        detail?: string;
        previous_admission?: { access_point: string; admitted_at: string };
        ticket?: { attendee_name: string };
      };

      if (errorObj?.status === 409) {
        // Anti-passback duplicate detection
        setFeedback({
          type: 'duplicate',
          title: 'Already Checked In',
          detail: errorObj.detail || 'Previously admitted at another gate.',
          attendeeName: errorObj.ticket?.attendee_name,
          accessPoint: errorObj.previous_admission?.access_point,
          previousAdmittedAt: errorObj.previous_admission?.admitted_at,
        });
      } else if (errorObj?.status === 0) {
        // Network disconnected / fail closed
        setFeedback({
          type: 'unavailable',
          title: 'Server Unavailable',
          detail: 'Reconnect to the event network before scanning.',
        });
      } else {
        // Invalid or revoked
        setFeedback({
          type: 'invalid',
          title: 'Invalid Ticket',
          detail: errorObj?.detail || errorObj?.message || 'This ticket could not be verified.',
        });
      }
    } finally {
      setIsProcessing(false);
      // Debounce window to prevent double triggers
      setTimeout(() => {
        isCooldownRef.current = false;
      }, 2500);
    }
  };

  const handleBarcodeScanned = ({ data }: { data: string }) => {
    if (data) {
      processScanPayload(data);
    }
  };

  const handleManualSubmit = () => {
    if (!manualCode.trim()) return;
    setIsManualModalVisible(false);
    processScanPayload(manualCode.trim());
    setManualCode('');
  };

  if (!permission) {
    return (
      <View style={styles.centerContainer}>
        <ActivityIndicator size="large" color="#800000" />
      </View>
    );
  }

  if (!permission.granted && Platform.OS !== 'web') {
    return (
      <View style={styles.centerContainer}>
        <Text style={styles.permissionTitle}>Camera Permission Required</Text>
        <Text style={styles.permissionSubtitle}>
          Ingress needs camera access to scan attendee QR tickets at entrance gates.
        </Text>
        <Pressable style={styles.grantButton} onPress={requestPermission}>
          <Text style={styles.grantButtonText}>Grant Camera Permission</Text>
        </Pressable>
      </View>
    );
  }

  return (
    <View style={styles.container}>
      {/* Viewfinder Header info */}
      <View style={styles.viewfinderHeader}>
        <View style={styles.gatePill}>
          <Text style={styles.gatePillText}>GATE: {accessPointName || 'Main Entrance'}</Text>
        </View>
        <Text style={styles.eventSubTitle} numberOfLines={1}>{eventTitle || 'Campus Event'}</Text>
      </View>

      {/* Camera Viewfinder */}
      <View style={styles.cameraContainer}>
        {Platform.OS !== 'web' ? (
          <CameraView
            style={StyleSheet.absoluteFill}
            enableTorch={torchEnabled}
            barcodeScannerSettings={{
              barcodeTypes: ['qr'],
            }}
            onBarcodeScanned={isProcessing ? undefined : handleBarcodeScanned}
          />
        ) : (
          <View style={[StyleSheet.absoluteFill, styles.webPlaceholder]}>
            <Pressable
              style={styles.simulateButton}
              onPress={() => setIsManualModalVisible(true)}
            >
              <Text style={styles.simulateButtonText}>Enter / Paste QR String</Text>
            </Pressable>
          </View>
        )}

        {/* Reticle / Aiming Guide */}
        <View style={styles.overlay}>
          <View style={styles.reticleBox}>
            <View style={[styles.corner, styles.cornerTL]} />
            <View style={[styles.corner, styles.cornerTR]} />
            <View style={[styles.corner, styles.cornerBL]} />
            <View style={[styles.corner, styles.cornerBR]} />
            {isProcessing && (
              <ActivityIndicator size="large" color="#ffffff" style={styles.loadingSpinner} />
            )}
          </View>
          <Text style={styles.aimGuideText}>Align QR code within frame</Text>
        </View>
      </View>

      {/* Prominent Multi-Sensory Feedback Card (Never relies on color alone) */}
      <View style={styles.feedbackContainer}>
        {feedback ? (
          <View
            style={[
              styles.feedbackCard,
              feedback.type === 'valid' && styles.feedbackValid,
              feedback.type === 'duplicate' && styles.feedbackDuplicate,
              feedback.type === 'invalid' && styles.feedbackInvalid,
              feedback.type === 'unavailable' && styles.feedbackUnavailable,
            ]}
          >
            <View style={styles.feedbackHeaderRow}>
              <View style={styles.feedbackIconBadge}>
                <Text style={styles.feedbackIconText}>
                  {feedback.type === 'valid' && '[✓]'}
                  {feedback.type === 'duplicate' && '[!]'}
                  {feedback.type === 'invalid' && '[✕]'}
                  {feedback.type === 'unavailable' && '[⚡]'}
                </Text>
              </View>
              <Text style={styles.feedbackTitle}>{feedback.title}</Text>
            </View>

            <Text style={styles.feedbackDetail}>{feedback.detail}</Text>

            {feedback.attendeeName && (
              <View style={styles.attendeeRow}>
                <Text style={styles.attendeeLabel}>Attendee:</Text>
                <Text style={styles.attendeeNameText}>
                  {feedback.attendeeName} {feedback.studentId ? `(${feedback.studentId})` : ''}
                </Text>
              </View>
            )}

            <Pressable
              style={styles.dismissButton}
              onPress={() => setFeedback(null)}
            >
              <Text style={styles.dismissButtonText}>Scan Next Ticket</Text>
            </Pressable>
          </View>
        ) : (
          <View style={styles.readyCard}>
            <Text style={styles.readyText}>Ready for Scan</Text>
            <Text style={styles.readySubtext}>Holding camera steady at attendee ticket</Text>
          </View>
        )}
      </View>

      {/* Scanner Controls Toolbar */}
      <View style={styles.toolbar}>
        <Pressable
          style={[styles.toolButton, torchEnabled && styles.toolButtonActive]}
          onPress={() => setTorchEnabled((prev) => !prev)}
        >
          <Text style={styles.toolButtonText}>{torchEnabled ? 'Torch: ON' : 'Torch: OFF'}</Text>
        </Pressable>

        <Pressable
          style={styles.toolButton}
          onPress={() => setIsManualModalVisible(true)}
        >
          <Text style={styles.toolButtonText}>Manual Input</Text>
        </Pressable>

        <Pressable
          style={styles.toolButton}
          onPress={() => router.back()}
        >
          <Text style={styles.toolButtonText}>Change Gate</Text>
        </Pressable>
      </View>

      {/* Manual Input Modal */}
      <Modal
        visible={isManualModalVisible}
        transparent
        animationType="fade"
        onRequestClose={() => setIsManualModalVisible(false)}
      >
        <View style={styles.modalBackdrop}>
          <View style={styles.modalContent}>
            <Text style={styles.modalTitle}>Manual Ticket Verification</Text>
            <Text style={styles.modalSubtitle}>
              Paste or type the QR payload or public ticket reference code.
            </Text>

            <TextInput
              style={styles.modalInput}
              value={manualCode}
              onChangeText={setManualCode}
              placeholder="ingress:v1:ING-XXXX:secret..."
              autoCapitalize="none"
              autoCorrect={false}
            />

            <View style={styles.modalButtonRow}>
              <Pressable
                style={[styles.modalButton, styles.modalCancelButton]}
                onPress={() => setIsManualModalVisible(false)}
              >
                <Text style={styles.modalCancelText}>Cancel</Text>
              </Pressable>
              <Pressable
                style={[styles.modalButton, styles.modalSubmitButton]}
                onPress={handleManualSubmit}
              >
                <Text style={styles.modalSubmitText}>Verify Ticket</Text>
              </Pressable>
            </View>
          </View>
        </View>
      </Modal>
    </View>
  );
}

const styles = StyleSheet.create({
  container: {
    flex: 1,
    backgroundColor: '#0f172a',
  },
  centerContainer: {
    flex: 1,
    justifyContent: 'center',
    alignItems: 'center',
    padding: 24,
    backgroundColor: '#ffffff',
  },
  permissionTitle: {
    fontSize: 18,
    fontWeight: '700',
    color: '#0f172a',
    marginBottom: 8,
    textAlign: 'center',
  },
  permissionSubtitle: {
    fontSize: 13,
    color: '#64748b',
    textAlign: 'center',
    marginBottom: 20,
    lineHeight: 18,
  },
  grantButton: {
    backgroundColor: '#800000',
    paddingHorizontal: 20,
    paddingVertical: 12,
    borderRadius: 6,
  },
  grantButtonText: {
    color: '#ffffff',
    fontWeight: '700',
    fontSize: 14,
  },
  viewfinderHeader: {
    padding: 12,
    backgroundColor: '#1e293b',
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'space-between',
  },
  gatePill: {
    backgroundColor: '#800000',
    paddingHorizontal: 10,
    paddingVertical: 4,
    borderRadius: 4,
    borderWidth: 1,
    borderColor: '#c5a059',
  },
  gatePillText: {
    color: '#ffffff',
    fontSize: 11,
    fontWeight: '800',
  },
  eventSubTitle: {
    color: '#94a3b8',
    fontSize: 13,
    fontWeight: '600',
    flex: 1,
    textAlign: 'right',
    marginLeft: 10,
  },
  cameraContainer: {
    flex: 1,
    overflow: 'hidden',
    position: 'relative',
    backgroundColor: '#000000',
  },
  webPlaceholder: {
    backgroundColor: '#1e293b',
    justifyContent: 'center',
    alignItems: 'center',
    padding: 20,
  },
  webPlaceholderText: {
    color: '#94a3b8',
    fontSize: 14,
    marginBottom: 16,
  },
  simulateButton: {
    backgroundColor: '#800000',
    paddingHorizontal: 16,
    paddingVertical: 10,
    borderRadius: 6,
  },
  simulateButtonText: {
    color: '#ffffff',
    fontWeight: '700',
    fontSize: 13,
  },
  overlay: {
    top: 0,
    left: 0,
    right: 0,
    bottom: 0,
    position: 'absolute',
    justifyContent: 'center',
    alignItems: 'center',
  },
  reticleBox: {
    width: 250,
    height: 250,
    position: 'relative',
    justifyContent: 'center',
    alignItems: 'center',
  },
  corner: {
    position: 'absolute',
    width: 28,
    height: 28,
    borderColor: '#ffffff',
  },
  cornerTL: {
    top: 0,
    left: 0,
    borderTopWidth: 4,
    borderLeftWidth: 4,
  },
  cornerTR: {
    top: 0,
    right: 0,
    borderTopWidth: 4,
    borderRightWidth: 4,
  },
  cornerBL: {
    bottom: 0,
    left: 0,
    borderBottomWidth: 4,
    borderLeftWidth: 4,
  },
  cornerBR: {
    bottom: 0,
    right: 0,
    borderBottomWidth: 4,
    borderRightWidth: 4,
  },
  loadingSpinner: {
    backgroundColor: 'rgba(0, 0, 0, 0.5)',
    padding: 20,
    borderRadius: 8,
  },
  aimGuideText: {
    color: '#ffffff',
    fontSize: 12,
    fontWeight: '600',
    marginTop: 16,
    backgroundColor: 'rgba(0, 0, 0, 0.6)',
    paddingHorizontal: 12,
    paddingVertical: 4,
    borderRadius: 12,
  },
  feedbackContainer: {
    padding: 16,
    backgroundColor: '#0f172a',
  },
  feedbackCard: {
    borderRadius: 8,
    padding: 16,
    borderWidth: 2,
  },
  feedbackValid: {
    backgroundColor: '#14532d',
    borderColor: '#22c55e',
  },
  feedbackDuplicate: {
    backgroundColor: '#78350f',
    borderColor: '#f59e0b',
  },
  feedbackInvalid: {
    backgroundColor: '#7f1d1d',
    borderColor: '#ef4444',
  },
  feedbackUnavailable: {
    backgroundColor: '#1e293b',
    borderColor: '#64748b',
  },
  feedbackHeaderRow: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: 8,
    marginBottom: 6,
  },
  feedbackIconBadge: {
    backgroundColor: 'rgba(255, 255, 255, 0.2)',
    paddingHorizontal: 6,
    paddingVertical: 2,
    borderRadius: 4,
  },
  feedbackIconText: {
    color: '#ffffff',
    fontSize: 14,
    fontWeight: '800',
  },
  feedbackTitle: {
    fontSize: 20,
    fontWeight: '900',
    color: '#ffffff',
    letterSpacing: 0.5,
  },
  feedbackDetail: {
    fontSize: 14,
    color: '#f8fafc',
    marginBottom: 8,
    lineHeight: 18,
    fontWeight: '500',
  },
  attendeeRow: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: 6,
    marginBottom: 12,
    backgroundColor: 'rgba(0, 0, 0, 0.2)',
    padding: 8,
    borderRadius: 4,
  },
  attendeeLabel: {
    color: '#e2e8f0',
    fontSize: 12,
    fontWeight: '600',
  },
  attendeeNameText: {
    color: '#ffffff',
    fontSize: 13,
    fontWeight: '700',
  },
  dismissButton: {
    backgroundColor: '#ffffff',
    paddingVertical: 10,
    borderRadius: 6,
    alignItems: 'center',
    marginTop: 4,
  },
  dismissButtonText: {
    color: '#0f172a',
    fontSize: 13,
    fontWeight: '800',
    textTransform: 'uppercase',
    letterSpacing: 0.5,
  },
  readyCard: {
    backgroundColor: '#1e293b',
    borderRadius: 8,
    borderWidth: 1,
    borderColor: '#334155',
    padding: 16,
    alignItems: 'center',
  },
  readyText: {
    color: '#ffffff',
    fontSize: 16,
    fontWeight: '700',
    marginBottom: 2,
  },
  readySubtext: {
    color: '#94a3b8',
    fontSize: 12,
  },
  toolbar: {
    flexDirection: 'row',
    padding: 12,
    backgroundColor: '#1e293b',
    borderTopWidth: 1,
    borderTopColor: '#334155',
    gap: 10,
  },
  toolButton: {
    flex: 1,
    backgroundColor: '#334155',
    paddingVertical: 10,
    borderRadius: 6,
    alignItems: 'center',
  },
  toolButtonActive: {
    backgroundColor: '#c5a059',
  },
  toolButtonText: {
    color: '#ffffff',
    fontSize: 12,
    fontWeight: '700',
  },
  modalBackdrop: {
    flex: 1,
    backgroundColor: 'rgba(0, 0, 0, 0.7)',
    justifyContent: 'center',
    alignItems: 'center',
    padding: 20,
  },
  modalContent: {
    width: '100%',
    maxWidth: 400,
    backgroundColor: '#ffffff',
    borderRadius: 8,
    padding: 20,
  },
  modalTitle: {
    fontSize: 17,
    fontWeight: '700',
    color: '#0f172a',
    marginBottom: 4,
  },
  modalSubtitle: {
    fontSize: 12,
    color: '#64748b',
    marginBottom: 16,
    lineHeight: 16,
  },
  modalInput: {
    height: 48,
    borderWidth: 1,
    borderColor: '#cbd5e1',
    borderRadius: 6,
    paddingHorizontal: 12,
    fontSize: 13,
    fontFamily: 'monospace',
    color: '#0f172a',
    backgroundColor: '#f8fafc',
    marginBottom: 16,
  },
  modalButtonRow: {
    flexDirection: 'row',
    gap: 10,
  },
  modalButton: {
    flex: 1,
    height: 42,
    borderRadius: 6,
    justifyContent: 'center',
    alignItems: 'center',
  },
  modalCancelButton: {
    backgroundColor: '#f1f5f9',
  },
  modalCancelText: {
    color: '#475569',
    fontWeight: '600',
    fontSize: 13,
  },
  modalSubmitButton: {
    backgroundColor: '#800000',
  },
  modalSubmitText: {
    color: '#ffffff',
    fontWeight: '700',
    fontSize: 13,
  },
});

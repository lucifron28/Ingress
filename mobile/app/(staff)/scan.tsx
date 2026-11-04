import * as Crypto from 'expo-crypto';
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
import { ApiError, apiRequest } from '../../src/services/api-client';
import { nodeRequest } from '../../src/services/node-session';
import { useNodeStaff } from '../../src/context/NodeStaffContext';

interface ScanFeedback {
  type: 'valid' | 'duplicate' | 'conflict' | 'invalid' | 'unavailable';
  title: string;
  detail: string;
  attendeeName?: string;
  studentId?: string;
  accessPoint?: string;
  previousAdmittedAt?: string;
}

interface PendingPreview {
  clientScanId: string;
  qrPayload: string;
  direction: 'entry' | 'exit';
  publicId: string;
  attendeeName: string;
  studentId?: string | null;
  profilePhotoUrl?: string | null;
  identityVerificationLevel: 'standard' | 'id_check';
}
export default function ScannerScreen() {
  const node = useNodeStaff();
  const request = node.active ? nodeRequest : apiRequest;
  const { eventId, eventTitle, accessPointId, accessPointName } = useLocalSearchParams<{
    eventId: string;
    eventTitle: string;
    accessPointId: string;
    accessPointName: string;
  }>();

  const [permission, requestPermission] = useCameraPermissions();
  const [torchEnabled, setTorchEnabled] = useState(false);
  const [direction, setDirection] = useState<'entry' | 'exit'>('entry');
  const [isValidating, setIsValidating] = useState(false);
  const [isConfirming, setIsConfirming] = useState(false);
  const [pendingPreview, setPendingPreview] = useState<PendingPreview | null>(null);
  const [identityCheckConfirmed, setIdentityCheckConfirmed] = useState(false);
  const [confirmError, setConfirmError] = useState<string | null>(null);
  const [feedback, setFeedback] = useState<ScanFeedback | null>(null);
  const [isManualModalVisible, setIsManualModalVisible] = useState(false);
  const [manualCode, setManualCode] = useState('');
  const isCooldownRef = useRef(false);

  const handleApiError = (err: unknown) => {
    if (err instanceof ApiError) {
      const status = err.status;
      const domainStatus = err.domainStatus;
      const detail = err.detail;
      const prevAdmission = err.data?.previous_admission;
      const ticket = err.data?.ticket;

      if (domainStatus === 'not_inside') {
        setFeedback({
          type: 'duplicate',
          title: 'Attendee Already Outside',
          detail: detail || 'No confirmed entry exists for this ticket.',
          attendeeName: ticket?.attendee_name,
          studentId: ticket?.student_id || undefined,
        });
        return;
      }

      if (domainStatus === 'reentry_not_allowed') {
        setFeedback({
          type: 'duplicate',
          title: 'Re-entry Not Allowed',
          detail: detail || 'This event does not allow re-entry after an exit.',
          attendeeName: ticket?.attendee_name,
          studentId: ticket?.student_id || undefined,
          accessPoint: prevAdmission?.access_point,
          previousAdmittedAt: prevAdmission?.admitted_at,
        });
        return;
      }

      if (domainStatus === 'already_admitted' || (status === 409 && domainStatus !== 'idempotency_conflict')) {
        setFeedback({
          type: 'duplicate',
          title: 'Already Checked In',
          detail: detail || 'Previously admitted at another gate.',
          attendeeName: ticket?.attendee_name,
          studentId: ticket?.student_id || undefined,
          accessPoint: prevAdmission?.access_point,
          previousAdmittedAt: prevAdmission?.admitted_at,
        });
        return;
      }

      if (domainStatus === 'idempotency_conflict') {
        setFeedback({
          type: 'conflict',
          title: 'Admission Request Conflict',
          detail: detail || 'The scan attempt ID conflicted with another request. Please scan again.',
        });
        return;
      }

      if (domainStatus === 'revoked_ticket') {
        setFeedback({
          type: 'invalid',
          title: 'Revoked Ticket',
          detail: detail || 'Attendee ticket has been revoked and cannot be admitted.',
        });
        return;
      }

      if (domainStatus === 'wrong_event') {
        setFeedback({
          type: 'invalid',
          title: 'Wrong Event',
          detail: detail || 'This ticket was issued for a different event.',
        });
        return;
      }

      if (domainStatus === 'event_cancelled') {
        setFeedback({
          type: 'invalid',
          title: 'Event Cancelled',
          detail: detail || 'This event has been cancelled and cannot admit attendees.',
        });
        return;
      }

      if (domainStatus === 'event_completed') {
        setFeedback({
          type: 'invalid',
          title: 'Admission Closed',
          detail: detail || 'This event has already concluded.',
        });
        return;
      }

      if (domainStatus === 'unauthorized_staff' || domainStatus === 'gate_mismatch' || status === 403) {
        setFeedback({
          type: 'invalid',
          title: 'Unauthorized Access Point',
          detail: detail || 'You are not assigned to operate entrance scanners for this gate.',
        });
        return;
      }

      if (status === 0 || domainStatus === 'unavailable') {
        setFeedback({
          type: 'unavailable',
          title: 'Server Unavailable',
          detail: 'Reconnect to the event network before scanning.',
        });
        return;
      }

      setFeedback({
        type: 'invalid',
        title: 'Invalid Ticket',
        detail: detail || err.message || 'This ticket could not be verified.',
      });
      return;
    }

    setFeedback({
      type: 'invalid',
      title: 'Invalid Ticket',
      detail: err instanceof Error ? err.message : 'This ticket could not be verified.',
    });
  };

  const processScanPayload = async (rawPayload: string) => {
    if (isCooldownRef.current || isValidating || isConfirming || pendingPreview || feedback) return;

    isCooldownRef.current = true;
    setIsValidating(true);
    setFeedback(null);
    setPendingPreview(null);
    setIdentityCheckConfirmed(false);

    try {
      // Step 1: Read-only preview validation. Does NOT consume the ticket or create an Admission.
      const res = await request<{
        status: string;
        message: string;
        detail: string;
        ticket: {
          public_id: string;
          attendee_name: string;
          student_id?: string | null;
          profile_photo_url?: string | null;
          identity_verification_level: 'standard' | 'id_check';
        };
      }>(`/staff/events/${eventId}/admissions/preview`, {
        method: 'POST',
        body: JSON.stringify({
          access_point_id: parseInt(accessPointId || '0', 10),
          qr_payload: rawPayload.trim(),
          direction,
        }),
      });

      if (res.status === 'valid') {
        setConfirmError(null);
        setPendingPreview({
          clientScanId: Crypto.randomUUID(),
          qrPayload: rawPayload.trim(),
          direction,
          publicId: res.ticket.public_id,
          attendeeName: res.ticket.attendee_name,
          studentId: res.ticket.student_id,
          profilePhotoUrl: res.ticket.profile_photo_url,
          identityVerificationLevel: res.ticket.identity_verification_level ?? 'standard',
        });
        setIdentityCheckConfirmed(false);
      }
    } catch (err: unknown) {
      handleApiError(err);
    } finally {
      setIsValidating(false);
      setTimeout(() => {
        isCooldownRef.current = false;
      }, 1000);
    }
  };

  const handleCancelVerification = () => {
    // Operator chose "NOT THIS PERSON": clear pending preview without mutating backend.
    setPendingPreview(null);
    setConfirmError(null);
    setIdentityCheckConfirmed(false);
  };

  const handleConfirmAdmission = async () => {
    if (!pendingPreview || isConfirming
      || (pendingPreview.identityVerificationLevel === 'id_check' && !identityCheckConfirmed)) return;

    setIsConfirming(true);
    setConfirmError(null);
    const clientScanId = pendingPreview.clientScanId;
    const payloadToAdmit = pendingPreview.qrPayload;

    try {
      // Step 2: Confirmed admission transaction. Atomically validates under lock and creates Admission.
      const res = await request<{
        status: string;
        message: string;
        detail: string;
        admission?: { access_point: string; admitted_at: string; scanned_by: string };
        ticket?: { public_id: string; attendee_name: string; student_id: string };
      }>(`/staff/events/${eventId}/admissions`, {
        method: 'POST',
        body: JSON.stringify({
          access_point_id: parseInt(accessPointId || '0', 10),
          qr_payload: payloadToAdmit,
          client_scan_id: clientScanId,
          direction: pendingPreview.direction,
          identity_check_confirmed: pendingPreview.identityVerificationLevel === 'id_check' && identityCheckConfirmed,
        }),
      });

      setPendingPreview(null);
      setConfirmError(null);
      setIdentityCheckConfirmed(false);

      if (res.status === 'admitted' || res.status === 'exited') {
        setFeedback({
          type: 'valid',
          title: pendingPreview.direction === 'exit' ? 'Exit Recorded' : 'Entry Recorded',
          detail: res.detail || `${pendingPreview.direction === 'exit' ? 'Exit' : 'Entry'} recorded successfully.`,
          attendeeName: res.ticket?.attendee_name,
          studentId: res.ticket?.student_id,
          accessPoint: res.admission?.access_point || accessPointName,
        });
      }
    } catch (err: unknown) {
      // Differentiate ambiguous network/transport failure from definitive server rejection
      const isAmbiguous = (err instanceof ApiError && (err.status === 0 || err.domainStatus === 'unavailable' || err.status >= 500))
        || (!(err instanceof ApiError) && err instanceof Error);

      if (isAmbiguous) {
        // Preserve pendingPreview (including clientScanId and qrPayload) so operator can retry with SAME clientScanId
        const detailMessage = err instanceof ApiError ? err.message : (err instanceof Error ? err.message : 'Server unavailable');
        setConfirmError(detailMessage || 'Network unavailable. Press CONFIRM ADMISSION to retry.');
      } else {
        // Definitive response from server (already admitted, invalid ticket, unauthorized staff, etc.): clear pending preview
        setPendingPreview(null);
        setConfirmError(null);
        handleApiError(err);
      }
    } finally {
      setIsConfirming(false);
    }
  };

  const isScannerActive =
    !isValidating && !isConfirming && !pendingPreview && !feedback && !isManualModalVisible;

  const handleBarcodeScanned = ({ data }: { data: string }) => {
    if (data && isScannerActive) {
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

      <View style={styles.movementBar}>
        <Text style={styles.movementLabel}>SCAN MODE</Text>
        {(['entry', 'exit'] as const).map((value) => (
          <Pressable
            key={value}
            accessibilityRole="button"
            accessibilityState={{ selected: direction === value, disabled: !!pendingPreview || isValidating || isConfirming }}
            disabled={!!pendingPreview || isValidating || isConfirming}
            style={[styles.movementOption, direction === value && styles.movementOptionActive]}
            onPress={() => setDirection(value)}
          >
            <Text style={[styles.movementOptionText, direction === value && styles.movementOptionTextActive]}>
              {value.toUpperCase()}
            </Text>
          </Pressable>
        ))}
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
            onBarcodeScanned={isScannerActive ? handleBarcodeScanned : undefined}
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
            {(isValidating || isConfirming) && (
              <ActivityIndicator size="large" color="#ffffff" style={styles.loadingSpinner} />
            )}
          </View>
          <Text style={styles.aimGuideText}>
            {isValidating
              ? 'Validating ticket with event server...'
              : isConfirming
              ? `Recording ${direction}...`
              : pendingPreview
              ? `Ticket verified - Confirm ${pendingPreview.direction} below`
              : feedback
              ? 'Scan paused'
              : 'Align QR code within frame'}
          </Text>
        </View>
      </View>

      {/* Prominent Multi-Sensory Feedback Card (Never relies on color alone) */}
      <View style={styles.feedbackContainer}>
        {pendingPreview ? (
          <View style={styles.verifyCard}>
            <View style={styles.verifyHeaderRow}>
              <View style={styles.verifyBadge}>
                <Text style={styles.verifyBadgeText}>VERIFY ATTENDEE</Text>
              </View>
              <View style={styles.validPill}>
                <Text style={styles.validPillText}>Valid Ticket</Text>
              </View>
            </View>

            <View style={styles.identitySection}>
              <View style={styles.avatarCircle}>
                <Text style={styles.avatarInitials}>
                  {pendingPreview.attendeeName
                    .split(' ')
                    .map((n) => n[0])
                    .filter(Boolean)
                    .slice(0, 2)
                    .join('')
                    .toUpperCase() || 'ST'}
                </Text>
              </View>
              <View style={styles.identityMeta}>
                <Text style={styles.attendeeNameDisplay} numberOfLines={2}>
                  {pendingPreview.attendeeName}
                </Text>
                <Text style={styles.studentIdDisplay}>
                  ID: {pendingPreview.studentId || 'N/A'}
                </Text>
                <Text style={styles.ticketRefDisplay}>
                  Ref: {pendingPreview.publicId}
                </Text>
              </View>
            </View>

            <Text style={styles.verifyInstruction}>
              {pendingPreview.identityVerificationLevel === 'id_check'
                ? 'Compare the attendee with an approved photo ID before confirming this movement.'
                : 'Compare the attendee name and identifier with the ticket details. Student email and number alone do not prove enrollment.'}
            </Text>
            {pendingPreview.identityVerificationLevel === 'id_check' && (
              <Pressable
                accessibilityRole="checkbox"
                accessibilityState={{ checked: identityCheckConfirmed }}
                onPress={() => setIdentityCheckConfirmed((checked) => !checked)}
                disabled={isConfirming}
                style={{ flexDirection: 'row', alignItems: 'center', gap: 10, paddingVertical: 12 }}
              >
                <View style={{ width: 22, height: 22, borderWidth: 2, borderColor: '#6b7280', borderRadius: 4, alignItems: 'center', justifyContent: 'center', backgroundColor: identityCheckConfirmed ? '#7a0019' : '#ffffff' }}>
                  {identityCheckConfirmed && <Text style={{ color: '#ffffff', fontWeight: '700' }}>✓</Text>}
                </View>
                <Text style={{ flex: 1, color: '#1f2937', fontWeight: '600' }}>I checked the attendee’s approved ID</Text>
              </Pressable>
            )}
            {confirmError && (
              <View style={styles.confirmErrorBanner}>
                <Text style={styles.confirmErrorText}>{confirmError}</Text>
                <Text style={styles.confirmErrorSubtext}>
                  Connection interrupted. Retry this confirmation. The Event Node will not record it twice.
                </Text>
              </View>
            )}

            <View style={styles.verifyButtonRow}>
              <Pressable
                style={[styles.verifyButton, styles.rejectButton]}
                onPress={handleCancelVerification}
                disabled={isConfirming}
              >
                <Text style={styles.rejectButtonText}>NOT THIS PERSON</Text>
              </Pressable>
              <Pressable
                style={[styles.verifyButton, styles.confirmButton, isConfirming && styles.buttonDisabled]}
                onPress={handleConfirmAdmission}
                disabled={isConfirming || (pendingPreview.identityVerificationLevel === 'id_check' && !identityCheckConfirmed)}
              >
                {isConfirming ? (
                  <ActivityIndicator size="small" color="#ffffff" />
                ) : (
                  <Text style={styles.confirmButtonText}>CONFIRM {pendingPreview.direction.toUpperCase()}</Text>
                )}
              </Pressable>
            </View>
          </View>
        ) : feedback ? (
          <View
            style={[
              styles.feedbackCard,
              feedback.type === 'valid' && styles.feedbackValid,
              feedback.type === 'duplicate' && styles.feedbackDuplicate,
              feedback.type === 'conflict' && styles.feedbackConflict,
              feedback.type === 'invalid' && styles.feedbackInvalid,
              feedback.type === 'unavailable' && styles.feedbackUnavailable,
            ]}
          >
            <View style={styles.feedbackHeaderRow}>
              <View style={styles.feedbackIconBadge}>
                <Text style={styles.feedbackIconText}>
                  {feedback.type === 'valid' && '[✓]'}
                  {feedback.type === 'duplicate' && '[!]'}
                  {feedback.type === 'conflict' && '[!]'}
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

            {feedback.accessPoint && (
              <View style={styles.attendeeRow}>
                <Text style={styles.attendeeLabel}>Access Point:</Text>
                <Text style={styles.attendeeNameText}>{feedback.accessPoint}</Text>
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
            <Text style={styles.readyText}>
              {isValidating ? 'Validating Ticket...' : 'Ready for Scan'}
            </Text>
            <Text style={styles.readySubtext}>
              {isValidating
                ? 'Contacting local Ingress Event Node'
                : 'Hold camera steady at attendee ticket QR'}
            </Text>
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
              Enter or paste the complete QR payload (e.g. ingress:v1:ING-XXXX:secret). Full payload required for cryptographic verification.
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
  movementBar: {
    flexDirection: 'row',
    alignItems: 'center',
    paddingHorizontal: 12,
    paddingVertical: 8,
    backgroundColor: '#111827',
    gap: 8,
  },
  movementLabel: {
    color: '#94a3b8',
    fontSize: 10,
    fontWeight: '800',
    marginRight: 4,
  },
  movementOption: {
    flex: 1,
    paddingVertical: 8,
    borderRadius: 5,
    borderWidth: 1,
    borderColor: '#475569',
    alignItems: 'center',
  },
  movementOptionActive: {
    backgroundColor: '#800000',
    borderColor: '#c5a059',
  },
  movementOptionText: {
    color: '#cbd5e1',
    fontSize: 12,
    fontWeight: '800',
  },
  movementOptionTextActive: {
    color: '#ffffff',
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
  feedbackConflict: {
    backgroundColor: '#78350f',
    borderColor: '#d97706',
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
  verifyCard: {
    backgroundColor: '#1e293b',
    borderRadius: 8,
    borderWidth: 2,
    borderColor: '#c5a059',
    borderTopWidth: 4,
    borderTopColor: '#800000',
    padding: 16,
  },
  verifyHeaderRow: {
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'space-between',
    marginBottom: 12,
  },
  verifyBadge: {
    backgroundColor: '#800000',
    paddingHorizontal: 8,
    paddingVertical: 3,
    borderRadius: 4,
  },
  verifyBadgeText: {
    color: '#ffffff',
    fontSize: 11,
    fontWeight: '800',
    letterSpacing: 0.5,
  },
  validPill: {
    backgroundColor: '#dcfce7',
    paddingHorizontal: 8,
    paddingVertical: 3,
    borderRadius: 9999,
  },
  validPillText: {
    color: '#166534',
    fontSize: 11,
    fontWeight: '700',
  },
  identitySection: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: 12,
    backgroundColor: 'rgba(0, 0, 0, 0.25)',
    padding: 12,
    borderRadius: 6,
    marginBottom: 10,
  },
  avatarCircle: {
    width: 48,
    height: 48,
    borderRadius: 24,
    backgroundColor: '#800000',
    borderWidth: 2,
    borderColor: '#c5a059',
    justifyContent: 'center',
    alignItems: 'center',
  },
  avatarInitials: {
    color: '#ffffff',
    fontSize: 18,
    fontWeight: '800',
  },
  identityMeta: {
    flex: 1,
  },
  attendeeNameDisplay: {
    color: '#ffffff',
    fontSize: 17,
    fontWeight: '800',
    marginBottom: 2,
  },
  studentIdDisplay: {
    color: '#94a3b8',
    fontSize: 13,
    fontWeight: '700',
    fontFamily: Platform.OS === 'ios' ? 'Menlo' : 'monospace',
    marginBottom: 2,
  },
  ticketRefDisplay: {
    color: '#64748b',
    fontSize: 11,
    fontFamily: Platform.OS === 'ios' ? 'Menlo' : 'monospace',
  },
  verifyInstruction: {
    color: '#cbd5e1',
    fontSize: 12,
    marginBottom: 12,
    lineHeight: 16,
    fontStyle: 'italic',
  },
  verifyButtonRow: {
    flexDirection: 'row',
    gap: 10,
  },
  verifyButton: {
    flex: 1,
    paddingVertical: 12,
    borderRadius: 6,
    alignItems: 'center',
    justifyContent: 'center',
  },
  rejectButton: {
    backgroundColor: '#334155',
    borderWidth: 1,
    borderColor: '#475569',
  },
  rejectButtonText: {
    color: '#f1f5f9',
    fontSize: 12,
    fontWeight: '800',
    letterSpacing: 0.5,
  },
  confirmButton: {
    backgroundColor: '#15803d',
    borderWidth: 1,
    borderColor: '#22c55e',
  },
  confirmButtonText: {
    color: '#ffffff',
    fontSize: 12,
    fontWeight: '800',
    letterSpacing: 0.5,
  },
  buttonDisabled: {
    opacity: 0.6,
  },
  confirmErrorBanner: {
    backgroundColor: '#fff1f2',
    borderColor: '#f43f5e',
    borderWidth: 1,
    borderRadius: 8,
    padding: 10,
    marginBottom: 12,
  },
  confirmErrorText: {
    color: '#e11d48',
    fontSize: 13,
    fontWeight: '700',
    textAlign: 'center',
  },
  confirmErrorSubtext: {
    color: '#9f1239',
    fontSize: 11,
    textAlign: 'center',
    marginTop: 2,
  },
});

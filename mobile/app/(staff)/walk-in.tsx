import * as Crypto from 'expo-crypto';
import * as Print from 'expo-print';
import * as SecureStore from 'expo-secure-store';
import { useLocalSearchParams } from 'expo-router';
import React, { useEffect, useRef, useState } from 'react';
import { ActivityIndicator, Alert, Pressable, ScrollView, StyleSheet, Text, TextInput, View } from 'react-native';
import QRCode from 'react-native-qrcode-svg';
import { useNodeStaff } from '../../src/context/NodeStaffContext';
import { nodeRequest } from '../../src/services/node-session';

type Kind = 'student' | 'guest' | 'alumni';
type Request = { client_request_id: string; name: string; email: string; kind: Kind; student_id: string | null };
type Pass = { public_id: string; qr_payload: string; attendee_name: string };
type QrRef = { toDataURL: (callback: (data: string) => void) => void };

const options = { keychainAccessible: SecureStore.WHEN_UNLOCKED_THIS_DEVICE_ONLY };
const htmlEntities: Record<string, string> = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' };
const escapeHtml = (value: string) => value.replace(/[&<>"']/g, (c) => htmlEntities[c]);

export default function WalkInScreen() {
  const { eventId, eventTitle } = useLocalSearchParams<{ eventId: string; eventTitle: string }>();
  const node = useNodeStaff();
  const [name, setName] = useState('');
  const [email, setEmail] = useState('');
  const [studentId, setStudentId] = useState('');
  const [kind, setKind] = useState<Kind>('guest');
  const [storageKey, setStorageKey] = useState<string | null>(null);
  const [pending, setPending] = useState<Request | null>(null);
  const [pass, setPass] = useState<Pass | null>(null);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const qr = useRef<QrRef | null>(null);
  const submitting = useRef(false);
  const session = node.active ? node.session : null;

  useEffect(() => {
    let live = true;
    setStorageKey(null);
    setPending(null);
    setPass(null);
    async function restore() {
      if (!session || !/^[1-9][0-9]*$/.test(eventId ?? '')) return;
      try {
        const hash = await Crypto.digestStringAsync(Crypto.CryptoDigestAlgorithm.SHA256, `${session.url}:${session.user.id}:${eventId}`);
        const key = `ingress_walkin_${hash}`;
        const raw = await SecureStore.getItemAsync(key, options);
        if (!live) return;
        if (raw) {
          const saved = JSON.parse(raw) as Request;
          if (!saved || typeof saved.client_request_id !== 'string' || typeof saved.name !== 'string' || typeof saved.email !== 'string'
            || !['student', 'guest', 'alumni'].includes(saved.kind) || (saved.student_id !== null && typeof saved.student_id !== 'string')) {
            throw new Error('The saved walk-in request is invalid.');
          }
          setPending(saved);
        }
        setStorageKey(key);
      } catch {
        if (live) setError('Secure storage is unavailable. Walk-ins cannot be issued safely.');
      }
    }
    restore();
    return () => { live = false; };
  }, [eventId, session?.url, session?.user.id]);

  const submit = async () => {
    if (!storageKey || !session || submitting.current) return;
    const request: Request = pending ?? {
      client_request_id: Crypto.randomUUID(), name: name.trim(), email: email.trim().toLowerCase(), kind,
      student_id: kind === 'student' ? studentId.trim() : null,
    };
    if (!request.name || !request.email || (request.kind === 'student' && !request.student_id)) {
      setError('Enter the attendee name, email, and student ID when applicable.');
      return;
    }
    submitting.current = true;
    setBusy(true);
    setError(null);
    try {
      if (!pending) {
        await SecureStore.setItemAsync(storageKey, JSON.stringify(request), options);
        setPending(request);
      }
      const result = await nodeRequest<Pass>(`/staff/events/${eventId}/walk-ins`, { method: 'POST', body: JSON.stringify(request) });
      if (typeof result?.public_id !== 'string' || typeof result?.attendee_name !== 'string'
        || typeof result?.qr_payload !== 'string' || !/^ingress:v1:ING-[A-Z0-9]{10}:[a-f0-9]{64}$/.test(result.qr_payload)
        || !result.qr_payload.startsWith(`ingress:v1:${result.public_id}:`)) throw new Error('The Event Node returned an invalid pass.');
      setPass(result);
    } catch (err: unknown) {
      setError(err instanceof Error ? err.message : 'Could not issue this pass.');
    } finally {
      submitting.current = false;
      setBusy(false);
    }
  };

  const clear = () => Alert.alert('Finish this request?', 'Only start another request after the attendee has their pass or you have checked the previous request with the event organizer.', [
    { text: 'Keep request', style: 'cancel' },
    { text: 'Start another', onPress: async () => {
      if (!storageKey) return;
      try {
        await SecureStore.deleteItemAsync(storageKey, options);
        setPending(null); setPass(null); setName(''); setEmail(''); setStudentId(''); setKind('guest'); setError(null);
      } catch { setError('Could not clear the saved request.'); }
    } },
  ]);

  const print = async () => {
    const qrImage = qr.current;
    if (!pass || !qrImage) return;
    setBusy(true);
    try {
      const base64 = await new Promise<string>((resolve) => qrImage.toDataURL(resolve));
      if (!/^[A-Za-z0-9+/=]+$/.test(base64)) throw new Error('Could not prepare the QR image.');
      const student = pending?.student_id ? `<p>Student ID: ${escapeHtml(pending.student_id)}</p>` : '';
      await Print.printAsync({ html: `<!DOCTYPE html><html><head><meta charset="utf-8"><style>body{font-family:sans-serif;text-align:center;padding:32px}img{width:240px;height:240px}.code{font-family:monospace;font-size:10px;overflow-wrap:anywhere}</style></head><body><h1>${escapeHtml(eventTitle ?? 'Event pass')}</h1><p>WALK-IN</p><p>${escapeHtml(pass.attendee_name)}</p>${student}<img alt="Ticket QR code" src="data:image/png;base64,${base64}"><p>${escapeHtml(pass.public_id)}</p><p>Manual entry code, keep private:</p><p class="code">${escapeHtml(pass.qr_payload)}</p><p>Keep this pass private. Present it at the event entrance.</p></body></html>` });
    } catch { setError('Could not open the print dialog. Check the printer and try again.'); }
    finally { setBusy(false); }
  };

  if (!session) return <View style={styles.page}><Text>Connect to the Event Node to register walk-ins.</Text></View>;
  return (
    <ScrollView contentContainerStyle={styles.page} keyboardShouldPersistTaps="handled">
      <Text style={styles.heading}>{eventTitle}</Text>
      <Text style={styles.description}>Register one attendee. Staff must verify their identity at admission.</Text>
      {error && <Text accessibilityRole="alert" style={styles.error}>{error}</Text>}
      {!storageKey ? <ActivityIndicator /> : pass ? (
        <View style={styles.pass}>
          <Text style={styles.heading}>{pass.attendee_name}</Text>
          <QRCode value={pass.qr_payload} size={240} quietZone={12} getRef={(ref: QrRef | null) => { qr.current = ref; }} />
          <Text style={styles.reference}>{pass.public_id}</Text>
          <Text style={styles.description}>Give this pass to the attendee. Admission still requires a scan and confirmation.</Text>
          <Pressable accessibilityRole="button" style={styles.button} disabled={busy} onPress={print}><Text style={styles.buttonText}>Print pass</Text></Pressable>
          <Pressable accessibilityRole="button" style={styles.secondary} disabled={busy} onPress={clear}><Text>Register another</Text></Pressable>
        </View>
      ) : pending ? (
        <View>
          <Text style={styles.label}>Saved request for {pending.name}</Text>
          <Text style={styles.description}>Retry to recover the same pass if the connection was interrupted.</Text>
          <Pressable accessibilityRole="button" style={styles.button} disabled={busy} onPress={submit}><Text style={styles.buttonText}>{busy ? 'Contacting node...' : 'Retry request'}</Text></Pressable>
          <Pressable accessibilityRole="button" style={styles.secondary} disabled={busy} onPress={clear}><Text>Clear request</Text></Pressable>
        </View>
      ) : (
        <View>
          <Text style={styles.label}>Attendee type</Text>
          <View style={styles.row}>{(['student', 'guest', 'alumni'] as Kind[]).map((item) => (
            <Pressable key={item} accessibilityRole="radio" accessibilityState={{ checked: kind === item }} onPress={() => setKind(item)} style={[styles.choice, kind === item && styles.selected]}><Text style={kind === item ? styles.buttonText : undefined}>{item}</Text></Pressable>
          ))}</View>
          <Text style={styles.label}>Full name</Text><TextInput style={styles.input} value={name} onChangeText={setName} maxLength={255} autoComplete="name" />
          <Text style={styles.label}>Email</Text><TextInput style={styles.input} value={email} onChangeText={setEmail} maxLength={255} autoCapitalize="none" keyboardType="email-address" autoComplete="email" />
          {kind === 'student' && <><Text style={styles.label}>Student ID</Text><TextInput style={styles.input} value={studentId} onChangeText={setStudentId} maxLength={255} autoCapitalize="none" /></>}
          <Pressable accessibilityRole="button" style={styles.button} disabled={busy} onPress={submit}><Text style={styles.buttonText}>{busy ? 'Registering...' : 'Issue pass'}</Text></Pressable>
        </View>
      )}
    </ScrollView>
  );
}

const styles = StyleSheet.create({
  page: { flexGrow: 1, backgroundColor: '#f8fafc', padding: 20, gap: 12 },
  heading: { fontSize: 20, fontWeight: '700', color: '#0f172a' },
  description: { fontSize: 14, color: '#475569', lineHeight: 20 },
  error: { color: '#991b1b', backgroundColor: '#fee2e2', padding: 12, borderRadius: 6 },
  label: { fontSize: 14, fontWeight: '700', marginTop: 12, marginBottom: 6, color: '#1e293b' },
  input: { backgroundColor: '#fff', borderWidth: 1, borderColor: '#94a3b8', borderRadius: 6, padding: 12, fontSize: 16 },
  row: { flexDirection: 'row', gap: 8 },
  choice: { flex: 1, alignItems: 'center', padding: 10, borderWidth: 1, borderColor: '#94a3b8', borderRadius: 6 },
  selected: { backgroundColor: '#800000', borderColor: '#800000' },
  button: { backgroundColor: '#800000', padding: 14, borderRadius: 6, alignItems: 'center', marginTop: 18 },
  buttonText: { color: '#fff', fontWeight: '700' },
  secondary: { padding: 14, alignItems: 'center' },
  pass: { alignItems: 'center', gap: 16, backgroundColor: '#fff', padding: 20, borderRadius: 8 },
  reference: { color: '#475569', fontSize: 14 },
});

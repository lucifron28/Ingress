import { router } from 'expo-router';
import React, { useEffect, useState } from 'react';
import { ActivityIndicator, KeyboardAvoidingView, Platform, Pressable, ScrollView, StyleSheet, Text, TextInput, View } from 'react-native';
import { useNodeStaff } from '../src/context/NodeStaffContext';
import { useAuth } from '../src/context/AuthContext';
import { canUseStaffEntry } from '../src/services/staff-access';
import { getNodeSession } from '../src/services/node-session';
import type { NodeSession } from '../src/services/node-session';

export default function NodeStaffLoginScreen() {
  const node = useNodeStaff();
  const { user, isLoading } = useAuth();
  const [saved, setSaved] = useState<NodeSession | null>(null);
  const [url, setUrl] = useState('');
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    getNodeSession().then((session) => {
      setSaved(session);
      if (session) { setUrl(session.url); setEmail(session.user.email); }
    }).catch(() => setError('Secure storage is unavailable. Try again.'));
  }, []);

  const signIn = async () => {
    if (!url.trim() || !email.trim() || !password) {
      setError('Enter the Event Node address, staff email, and local password.');
      return;
    }
    setBusy(true);
    setError(null);
    try {
      await node.login(url, email, password);
      setPassword('');
      router.replace('/(staff)/events');
    } catch (err: unknown) {
      setError((err as { message?: string })?.message || 'Staff sign-in failed.');
    } finally { setBusy(false); }
  };

  const resume = async () => {
    setBusy(true);
    setError(null);
    try {
      await node.resume();
      router.replace('/(staff)/events');
    } catch (err: unknown) {
      setError((err as { message?: string })?.message || 'Could not restore the staff session.');
    } finally { setBusy(false); }
  };

  if (isLoading) return <View style={styles.page}><ActivityIndicator size="large" color="#800000" /></View>;
  if (!canUseStaffEntry(user)) {
    return <View style={styles.content}><Text style={styles.body}>Staff scanner access is available to assigned staff.</Text><Pressable accessibilityRole="button" onPress={() => router.back()}><Text style={styles.secondaryText}>Back</Text></Pressable></View>;
  }

  return (
    <KeyboardAvoidingView behavior={Platform.OS === 'ios' ? 'padding' : undefined} style={styles.page}>
      <ScrollView contentContainerStyle={styles.content} keyboardShouldPersistTaps="handled">
        <View style={styles.card}>
          <Text style={styles.title}>Staff scanner sign in</Text>
          <Text style={styles.body}>Enter the HTTPS Event Node address and the local credential provided by your event operator. Confirm the address with the operator before signing in.</Text>
          <Text style={styles.notice}>Scanning requires an activated event and a connection to the venue node.</Text>
          {saved && <Pressable accessibilityRole="button" style={styles.secondary} disabled={busy} onPress={resume}><Text style={styles.secondaryText}>Continue as {saved.user.name}</Text></Pressable>}
          <Text style={styles.label}>Event Node API address</Text>
          <TextInput accessibilityLabel="Event Node API address" style={styles.input} value={url} onChangeText={setUrl} autoCapitalize="none" autoCorrect={false} keyboardType="url" placeholder="https://node.example/api/v1" editable={!busy} />
          <Text style={styles.label}>Staff email</Text>
          <TextInput accessibilityLabel="Staff email" style={styles.input} value={email} onChangeText={setEmail} autoCapitalize="none" autoCorrect={false} keyboardType="email-address" editable={!busy} />
          <Text style={styles.label}>Local staff password</Text>
          <TextInput accessibilityLabel="Local staff password" style={styles.input} value={password} onChangeText={setPassword} autoCapitalize="none" autoCorrect={false} secureTextEntry editable={!busy} />
          {error && <Text accessibilityRole="alert" style={styles.error}>{error}</Text>}
          <Pressable accessibilityRole="button" disabled={busy} onPress={signIn} style={[styles.button, busy && styles.disabled]}>
            {busy ? <ActivityIndicator color="#fff" /> : <Text style={styles.buttonText}>Sign in to node</Text>}
          </Pressable>
          <Pressable accessibilityRole="button" disabled={busy} onPress={() => router.back()} style={styles.back}><Text style={styles.secondaryText}>Back</Text></Pressable>
        </View>
      </ScrollView>
    </KeyboardAvoidingView>
  );
}

const styles = StyleSheet.create({
  page: { flex: 1, backgroundColor: '#f8fafc' },
  content: { flexGrow: 1, justifyContent: 'center', padding: 20 },
  card: { width: '100%', maxWidth: 420, alignSelf: 'center', backgroundColor: '#fff', borderRadius: 8, borderWidth: 1, borderColor: '#e2e8f0', borderTopWidth: 4, borderTopColor: '#800000', padding: 24 },
  title: { fontSize: 22, fontWeight: '800', color: '#0f172a', marginBottom: 12 },
  body: { fontSize: 15, lineHeight: 22, color: '#475569', marginBottom: 14 },
  notice: { color: '#7c2d12', fontSize: 14, lineHeight: 20, marginBottom: 8 },
  label: { fontSize: 14, fontWeight: '700', color: '#334155', marginTop: 14, marginBottom: 8 },
  input: { minHeight: 48, borderWidth: 1, borderColor: '#94a3b8', borderRadius: 6, paddingHorizontal: 12, color: '#0f172a', fontSize: 16 },
  error: { color: '#991b1b', marginTop: 10, fontSize: 14 },
  button: { minHeight: 48, alignItems: 'center', justifyContent: 'center', backgroundColor: '#800000', borderRadius: 6, marginTop: 20, paddingHorizontal: 12 },
  buttonText: { color: '#fff', fontWeight: '700', fontSize: 15 },
  disabled: { opacity: 0.6 },
  secondary: { minHeight: 48, alignItems: 'center', justifyContent: 'center', borderWidth: 1, borderColor: '#800000', borderRadius: 6 },
  secondaryText: { color: '#800000', fontWeight: '700' },
  back: { minHeight: 48, alignItems: 'center', justifyContent: 'center', marginTop: 6 },
});

import { router } from 'expo-router';
import React, { useState } from 'react';
import { ActivityIndicator, KeyboardAvoidingView, Platform, Pressable, ScrollView, StyleSheet, Text, TextInput, View } from 'react-native';
import { apiRequest } from '../src/services/api-client';

export default function StudentRegisterScreen() {
  const [email, setEmail] = useState('');
  const [busy, setBusy] = useState(false);
  const [sent, setSent] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const sendLink = async () => {
    const address = email.trim().toLowerCase();
    if (!/^[^@\s]+@student\.mseuf\.edu\.ph$/.test(address)) {
      setError('Enter your @student.mseuf.edu.ph email address.');
      return;
    }
    setBusy(true);
    setError(null);
    try {
      await apiRequest('/auth/student-verification', { method: 'POST', body: JSON.stringify({ email: address }) });
      setSent(true);
    } catch (err: unknown) {
      const failure = err as { status?: number; message?: string };
      setError(failure.status === 0 ? 'Could not reach the server. Connect and try again.' : failure.message || 'Could not request a link. Try again.');
    } finally {
      setBusy(false);
    }
  };

  return (
    <KeyboardAvoidingView behavior={Platform.OS === 'ios' ? 'padding' : undefined} style={styles.page}>
      <ScrollView contentContainerStyle={styles.content} keyboardShouldPersistTaps="handled">
        <View style={styles.card}>
          <Text style={styles.title}>Create a student account</Text>
          {sent ? (
            <>
              <Text style={styles.body}>If this address is eligible, check your inbox for a verification link. Open it to complete your account. The link expires after 30 minutes.</Text>
              <Pressable accessibilityRole="button" style={styles.button} onPress={() => router.replace('/login')}>
                <Text style={styles.buttonText}>Back to sign in</Text>
              </Pressable>
            </>
          ) : (
            <>
              <Text style={styles.body}>Use your university student email. We will send a link to verify that you can access it.</Text>
              <Text style={styles.label}>Student email</Text>
              <TextInput accessibilityLabel="Student email" style={styles.input} value={email} onChangeText={setEmail}
                autoCapitalize="none" autoCorrect={false} keyboardType="email-address" autoComplete="email"
                placeholder="you@student.mseuf.edu.ph" editable={!busy} />
              {error && <Text accessibilityRole="alert" style={styles.error}>{error}</Text>}
              <Pressable accessibilityRole="button" disabled={busy} onPress={sendLink} style={[styles.button, busy && styles.disabled]}>
                {busy ? <ActivityIndicator color="#fff" /> : <Text style={styles.buttonText}>Send verification link</Text>}
              </Pressable>
              <Pressable accessibilityRole="button" disabled={busy} onPress={() => router.back()} style={styles.back}>
                <Text style={styles.backText}>Back to sign in</Text>
              </Pressable>
            </>
          )}
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
  body: { fontSize: 15, lineHeight: 22, color: '#475569', marginBottom: 20 },
  label: { fontSize: 14, fontWeight: '700', color: '#334155', marginBottom: 8 },
  input: { minHeight: 48, borderWidth: 1, borderColor: '#94a3b8', borderRadius: 6, paddingHorizontal: 12, color: '#0f172a', fontSize: 16 },
  error: { color: '#991b1b', marginTop: 10, fontSize: 14 },
  button: { minHeight: 48, alignItems: 'center', justifyContent: 'center', backgroundColor: '#800000', borderRadius: 6, marginTop: 20, paddingHorizontal: 12 },
  buttonText: { color: '#fff', fontWeight: '700', fontSize: 15 },
  disabled: { opacity: 0.6 },
  back: { minHeight: 48, alignItems: 'center', justifyContent: 'center', marginTop: 6 },
  backText: { color: '#800000', fontWeight: '700' },
});

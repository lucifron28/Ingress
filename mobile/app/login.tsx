import { router, useLocalSearchParams } from 'expo-router';
import React, { useState } from 'react';
import {
  ActivityIndicator,
  KeyboardAvoidingView,
  Platform,
  Pressable,
  ScrollView,
  StyleSheet,
  Text,
  TextInput,
  View,
} from 'react-native';
import { useAuth } from '../src/context/AuthContext';
import { DEMO_MODE } from '../src/services/api-client';

export default function LoginScreen() {
  const { login, serverUrl } = useAuth();
  const { notice } = useLocalSearchParams<{ notice?: string }>();
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState(DEMO_MODE ? 'password' : '');
  const [isSubmitting, setIsSubmitting] = useState(false);
  const [errorMessage, setErrorMessage] = useState<string | null>(null);

  const handleSubmit = async () => {
    if (!email.trim() || !password.trim()) {
      setErrorMessage('Please enter your university email and password.');
      return;
    }

    setIsSubmitting(true);
    setErrorMessage(null);

    try {
      await login(email.trim(), password);
      router.replace('/');
    } catch (err: unknown) {
      const errorObj = err as { message?: string };
      setErrorMessage(errorObj?.message || 'Login failed. Please verify credentials.');
    } finally {
      setIsSubmitting(false);
    }
  };

  const handleSelectDemoAccount = (demoEmail: string) => {
    setEmail(demoEmail);
    setPassword('password');
    setErrorMessage(null);
  };

  return (
    <KeyboardAvoidingView
      behavior={Platform.OS === 'ios' ? 'padding' : undefined}
      style={styles.keyboardContainer}
    >
      <ScrollView contentContainerStyle={styles.container}>
        <View style={styles.card}>
          <View style={styles.brandHeader}>
            <View style={styles.badgeContainer}>
              <Text style={styles.badgeText}>MSEUF</Text>
            </View>
            <Text style={styles.title}>INGRESS</Text>
            <Text style={styles.subtitle}>Campus Event Ticketing &amp; Admission</Text>
          </View>

          {notice === 'server-changed' && (
            <View style={styles.noticeBanner}>
              <Text style={styles.noticeText}>
                Event server changed. Sign in again to continue.
              </Text>
            </View>
          )}

          {errorMessage && (
            <View style={styles.errorBanner}>
              <Text style={styles.errorText}>{errorMessage}</Text>
            </View>
          )}

          <View style={styles.inputGroup}>
            <Text style={styles.label}>University Email</Text>
            <TextInput
              style={styles.input}
              placeholder="e.g. a23-35524@student.mseuf.edu.ph"
              value={email}
              onChangeText={setEmail}
              autoCapitalize="none"
              keyboardType="email-address"
              editable={!isSubmitting}
            />
          </View>

          <View style={styles.inputGroup}>
            <Text style={styles.label}>Password</Text>
            <TextInput
              style={styles.input}
              placeholder="••••••••"
              value={password}
              onChangeText={setPassword}
              secureTextEntry
              editable={!isSubmitting}
            />
          </View>

          <Pressable
            style={({ pressed }) => [
              styles.primaryButton,
              pressed && styles.primaryButtonPressed,
              isSubmitting && styles.buttonDisabled,
            ]}
            onPress={handleSubmit}
            disabled={isSubmitting}
          >
            {isSubmitting ? (
              <ActivityIndicator color="#ffffff" size="small" />
            ) : (
              <Text style={styles.primaryButtonText}>Sign In</Text>
            )}
          </Pressable>


          {/* Quick Demo Credentials — development and classroom builds only */}
          {DEMO_MODE && (
            <View style={styles.demoSection}>
              <Text style={styles.demoHeading}>Demo Quick Select</Text>
              <Pressable
                style={styles.demoChip}
                onPress={() => handleSelectDemoAccount('a23-35524@student.mseuf.edu.ph')}
              >
                <Text style={styles.demoChipRole}>STUDENT</Text>
                <Text style={styles.demoChipText}>Ron Vincent Cada (a23-35524@student.mseuf.edu.ph)</Text>
              </Pressable>
              <Pressable
                style={styles.demoChip}
                onPress={() => handleSelectDemoAccount('staff.gate_a@mseuf.edu.ph')}
              >
                <Text style={styles.demoChipRole}>STAFF</Text>
                <Text style={styles.demoChipText}>Alex Rivera (Entrance 1)</Text>
              </Pressable>
              <Pressable
                style={styles.demoChip}
                onPress={() => handleSelectDemoAccount('staff.gate_b@mseuf.edu.ph')}
              >
                <Text style={styles.demoChipRole}>STAFF</Text>
                <Text style={styles.demoChipText}>Bea Ramos (Entrance 2)</Text>
              </Pressable>
              <Pressable
                style={styles.demoChip}
                onPress={() => handleSelectDemoAccount('staff.gate_c@mseuf.edu.ph')}
              >
                <Text style={styles.demoChipRole}>STAFF</Text>
                <Text style={styles.demoChipText}>Chris Dizon (Entrance 3)</Text>
              </Pressable>
            </View>
          )}

          {/* LAN Server Indicator & Configuration Link */}
          <View style={styles.lanContainer}>
            <View style={styles.lanStatusRow}>
              <View style={styles.lanDot} />
              <Text style={styles.lanLabel}>LAN Host: {serverUrl}</Text>
            </View>
            <Pressable onPress={() => router.push('/server-settings')}>
              <Text style={styles.lanLink}>Change Server IP</Text>
            </Pressable>
          </View>
        </View>
      </ScrollView>
    </KeyboardAvoidingView>
  );
}

const styles = StyleSheet.create({
  keyboardContainer: {
    flex: 1,
    backgroundColor: '#f8fafc',
  },
  container: {
    padding: 20,
    flexGrow: 1,
    justifyContent: 'center',
    alignItems: 'center',
  },
  card: {
    width: '100%',
    maxWidth: 420,
    backgroundColor: '#ffffff',
    borderRadius: 8,
    borderWidth: 1,
    borderColor: '#e2e8f0',
    borderTopWidth: 4,
    borderTopColor: '#800000',
    padding: 24,
    shadowColor: '#000',
    shadowOffset: { width: 0, height: 2 },
    shadowOpacity: 0.05,
    shadowRadius: 4,
    elevation: 2,
  },
  brandHeader: {
    alignItems: 'center',
    marginBottom: 24,
  },
  badgeContainer: {
    backgroundColor: '#800000',
    paddingHorizontal: 8,
    paddingVertical: 3,
    borderRadius: 4,
    borderWidth: 1,
    borderColor: '#c5a059',
    marginBottom: 8,
  },
  badgeText: {
    color: '#ffffff',
    fontSize: 12,
    fontWeight: '800',
    letterSpacing: 0.5,
  },
  title: {
    fontSize: 22,
    fontWeight: '800',
    color: '#0f172a',
    letterSpacing: -0.5,
  },
  subtitle: {
    fontSize: 13,
    color: '#64748b',
    marginTop: 2,
  },
  noticeBanner: {
    backgroundColor: '#e0f2fe',
    borderLeftWidth: 4,
    borderLeftColor: '#0284c7',
    padding: 10,
    borderRadius: 4,
    marginBottom: 16,
  },
  noticeText: {
    color: '#075985',
    fontSize: 13,
    fontWeight: '500',
  },
  errorBanner: {
    backgroundColor: '#fee2e2',
    borderLeftWidth: 4,
    borderLeftColor: '#dc2626',
    padding: 10,
    borderRadius: 4,
    marginBottom: 16,
  },
  errorText: {
    color: '#991b1b',
    fontSize: 13,
    fontWeight: '500',
  },
  inputGroup: {
    marginBottom: 16,
  },
  label: {
    fontSize: 13,
    fontWeight: '600',
    color: '#334155',
    marginBottom: 6,
  },
  input: {
    height: 44,
    borderWidth: 1,
    borderColor: '#cbd5e1',
    borderRadius: 6,
    paddingHorizontal: 12,
    fontSize: 14,
    color: '#0f172a',
    backgroundColor: '#ffffff',
  },
  primaryButton: {
    backgroundColor: '#800000',
    height: 46,
    borderRadius: 6,
    justifyContent: 'center',
    alignItems: 'center',
    marginTop: 8,
    borderWidth: 1,
    borderColor: '#5b0000',
  },
  primaryButtonPressed: {
    backgroundColor: '#5b0000',
  },
  buttonDisabled: {
    opacity: 0.7,
  },
  primaryButtonText: {
    color: '#ffffff',
    fontSize: 15,
    fontWeight: '600',
  },
  demoSection: {
    marginTop: 24,
    paddingTop: 16,
    borderTopWidth: 1,
    borderTopColor: '#f1f5f9',
  },
  demoHeading: {
    fontSize: 11,
    fontWeight: '700',
    textTransform: 'uppercase',
    color: '#94a3b8',
    letterSpacing: 0.5,
    marginBottom: 8,
  },
  demoChip: {
    flexDirection: 'row',
    alignItems: 'center',
    backgroundColor: '#f8fafc',
    borderWidth: 1,
    borderColor: '#e2e8f0',
    borderRadius: 4,
    paddingVertical: 6,
    paddingHorizontal: 10,
    marginBottom: 6,
  },
  demoChipRole: {
    fontSize: 10,
    fontWeight: '700',
    color: '#800000',
    backgroundColor: '#fee2e2',
    paddingHorizontal: 5,
    paddingVertical: 2,
    borderRadius: 3,
    marginRight: 8,
  },
  demoChipText: {
    fontSize: 12,
    color: '#334155',
    flex: 1,
  },
  lanContainer: {
    marginTop: 20,
    paddingTop: 12,
    borderTopWidth: 1,
    borderTopColor: '#f1f5f9',
    alignItems: 'center',
  },
  lanStatusRow: {
    flexDirection: 'row',
    alignItems: 'center',
    marginBottom: 4,
  },
  lanDot: {
    width: 8,
    height: 8,
    borderRadius: 4,
    backgroundColor: '#16a34a',
    marginRight: 6,
  },
  lanLabel: {
    fontSize: 11,
    color: '#64748b',
  },
  lanLink: {
    fontSize: 12,
    color: '#800000',
    fontWeight: '600',
    textDecorationLine: 'underline',
  },
});

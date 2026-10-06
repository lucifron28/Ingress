import { router } from 'expo-router';
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
import { useAuth, UserProfile } from '../src/context/AuthContext';
import { apiRequest } from '../src/services/api-client';

interface StudentIdentityData {
  student_id: string;
  name: string;
  masked_email: string;
  college?: string | null;
}

interface RequestChallengeResponse {
  message: string;
  student_identity: StudentIdentityData;
  expires_at: string;
  demo_code?: string;
}

interface ConfirmActivationResponse {
  message: string;
  token: string;
  token_type: string;
  user: UserProfile;
  student_identity: {
    student_id: string;
    name: string;
    institutional_email: string;
    college?: string | null;
  };
}

export default function ActivateScreen() {
  const { setSession } = useAuth();

  // Multi-step state: 'request' -> 'confirm'
  const [step, setStep] = useState<'request' | 'confirm'>('request');

  // Step 1 Form
  const [identifier, setIdentifier] = useState('');

  // Step 2 Form
  const [identity, setIdentity] = useState<StudentIdentityData | null>(null);
  const [code, setCode] = useState('');
  const [password, setPassword] = useState('');
  const [passwordConfirmation, setPasswordConfirmation] = useState('');
  const [demoCode, setDemoCode] = useState<string | null>(null);

  // Status & error states
  const [isSubmitting, setIsSubmitting] = useState(false);
  const [errorMessage, setErrorMessage] = useState<string | null>(null);
  const [infoMessage, setInfoMessage] = useState<string | null>(null);

  const handleRequestChallenge = async () => {
    const trimmed = identifier.trim();
    if (!trimmed) {
      setErrorMessage('Please enter your student ID or institutional email.');
      return;
    }

    setIsSubmitting(true);
    setErrorMessage(null);
    setInfoMessage(null);

    try {
      const response = await apiRequest<RequestChallengeResponse>('/auth/activate/request', {
        method: 'POST',
        body: JSON.stringify({ identifier: trimmed }),
      });

      setIdentity(response.student_identity);
      if (response.demo_code) {
        setDemoCode(response.demo_code);
        setCode(response.demo_code); // Pre-fill in demo mode for instant flow
      }
      setStep('confirm');
      setInfoMessage(`Verification code sent to ${response.student_identity.masked_email}`);
    } catch (err: unknown) {
      const errorObj = err as { message?: string; detail?: string };
      setErrorMessage(errorObj?.detail || errorObj?.message || 'Unable to find student record.');
    } finally {
      setIsSubmitting(false);
    }
  };

  const handleConfirmActivation = async () => {
    if (!code.trim() || code.trim().length !== 6) {
      setErrorMessage('Please enter the 6-digit verification code.');
      return;
    }

    if (password.length < 8) {
      setErrorMessage('Password must be at least 8 characters long.');
      return;
    }

    if (password !== passwordConfirmation) {
      setErrorMessage('Passwords do not match.');
      return;
    }

    setIsSubmitting(true);
    setErrorMessage(null);

    try {
      const response = await apiRequest<ConfirmActivationResponse>('/auth/activate/confirm', {
        method: 'POST',
        body: JSON.stringify({
          identifier: identifier.trim(),
          code: code.trim(),
          password,
          password_confirmation: passwordConfirmation,
          device_name: 'Ingress-Mobile-Activated',
        }),
      });

      // Save user session in context and persistent storage
      await setSession(response.token, response.user);

      // Direct entry into student home
      router.replace('/(attendee)/events');
    } catch (err: unknown) {
      const errorObj = err as { message?: string; detail?: string; errors?: Record<string, string[]> };
      if (errorObj?.errors) {
        const firstErr = Object.values(errorObj.errors)[0]?.[0];
        setErrorMessage(firstErr || 'Failed to activate account.');
      } else {
        setErrorMessage(errorObj?.message || 'Activation failed. Please verify code and try again.');
      }
    } finally {
      setIsSubmitting(false);
    }
  };

  const handleResendCode = async () => {
    setIsSubmitting(true);
    setErrorMessage(null);
    try {
      const response = await apiRequest<RequestChallengeResponse>('/auth/activate/resend', {
        method: 'POST',
        body: JSON.stringify({ identifier: identifier.trim() }),
      });

      if (response.demo_code) {
        setDemoCode(response.demo_code);
        setCode(response.demo_code);
      }
      setInfoMessage(`New code sent to ${response.student_identity.masked_email}`);
    } catch (err: unknown) {
      const errorObj = err as { message?: string };
      setErrorMessage(errorObj?.message || 'Could not resend activation code.');
    } finally {
      setIsSubmitting(false);
    }
  };

  return (
    <KeyboardAvoidingView
      behavior={Platform.OS === 'ios' ? 'padding' : undefined}
      style={styles.keyboardContainer}
    >
      <ScrollView contentContainerStyle={styles.container}>
        <View style={styles.card}>
          {/* Header */}
          <View style={styles.brandHeader}>
            <View style={styles.badgeContainer}>
              <Text style={styles.badgeText}>MSEUF</Text>
            </View>
            <Text style={styles.title}>INGRESS</Text>
            <Text style={styles.subtitle}>Student Account Activation</Text>
          </View>

          {/* Feedback messages */}
          {infoMessage && (
            <View style={styles.infoBanner}>
              <Text style={styles.infoText}>{infoMessage}</Text>
            </View>
          )}

          {errorMessage && (
            <View style={styles.errorBanner}>
              <Text style={styles.errorText}>{errorMessage}</Text>
            </View>
          )}

          {step === 'request' ? (
            /* STEP 1: IDENTIFIER LOOKUP */
            <View>
              <Text style={styles.instructionText}>
                Enter your MSEUF Student ID or Institutional Email to verify your university record.
              </Text>

              <View style={styles.inputGroup}>
                <Text style={styles.label}>Student ID or Email</Text>
                <TextInput
                  style={styles.input}
                  placeholder="e.g. A23-35524 or name@student.mseuf.edu.ph"
                  value={identifier}
                  onChangeText={setIdentifier}
                  autoCapitalize="none"
                  editable={!isSubmitting}
                />
              </View>

              <Pressable
                style={({ pressed }) => [
                  styles.primaryButton,
                  pressed && styles.primaryButtonPressed,
                  isSubmitting && styles.buttonDisabled,
                ]}
                onPress={handleRequestChallenge}
                disabled={isSubmitting}
              >
                {isSubmitting ? (
                  <ActivityIndicator color="#ffffff" size="small" />
                ) : (
                  <Text style={styles.primaryButtonText}>Send Verification Code</Text>
                )}
              </Pressable>

              <Pressable
                style={styles.cancelLinkContainer}
                onPress={() => router.replace('/login')}
              >
                <Text style={styles.secondaryLinkText}>Already have an account? Sign In</Text>
              </Pressable>
            </View>
          ) : (
            /* STEP 2: CODE VERIFICATION & PASSWORD */
            <View>
              {identity && (
                <View style={styles.identityBadge}>
                  <Text style={styles.identityName}>{identity.name}</Text>
                  <Text style={styles.identityMeta}>
                    ID: {identity.student_id} {identity.college ? `• ${identity.college}` : ''}
                  </Text>
                  <Text style={styles.identityEmail}>Sent to: {identity.masked_email}</Text>
                </View>
              )}

              {demoCode && (
                <Pressable
                  style={styles.demoNotice}
                  onPress={() => setCode(demoCode)}
                >
                  <Text style={styles.demoNoticeText}>
                    Demo Mode OTP: <Text style={styles.demoNoticeCode}>{demoCode}</Text> (Tap to fill)
                  </Text>
                </Pressable>
              )}

              <View style={styles.inputGroup}>
                <Text style={styles.label}>6-Digit Verification Code</Text>
                <TextInput
                  style={[styles.input, styles.codeInput]}
                  placeholder="123456"
                  value={code}
                  onChangeText={setCode}
                  keyboardType="number-pad"
                  maxLength={6}
                  editable={!isSubmitting}
                />
              </View>

              <View style={styles.inputGroup}>
                <Text style={styles.label}>Create Password</Text>
                <TextInput
                  style={styles.input}
                  placeholder="At least 8 characters"
                  value={password}
                  onChangeText={setPassword}
                  secureTextEntry
                  editable={!isSubmitting}
                />
              </View>

              <View style={styles.inputGroup}>
                <Text style={styles.label}>Confirm Password</Text>
                <TextInput
                  style={styles.input}
                  placeholder="Repeat your password"
                  value={passwordConfirmation}
                  onChangeText={setPasswordConfirmation}
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
                onPress={handleConfirmActivation}
                disabled={isSubmitting}
              >
                {isSubmitting ? (
                  <ActivityIndicator color="#ffffff" size="small" />
                ) : (
                  <Text style={styles.primaryButtonText}>Activate &amp; Enter Ingress</Text>
                )}
              </Pressable>

              <View style={styles.subActions}>
                <Pressable onPress={handleResendCode} disabled={isSubmitting}>
                  <Text style={styles.subActionLink}>Resend Code</Text>
                </Pressable>
                <Text style={styles.subActionDivider}>•</Text>
                <Pressable onPress={() => { setStep('request'); setErrorMessage(null); }} disabled={isSubmitting}>
                  <Text style={styles.subActionLink}>Change Student ID</Text>
                </Pressable>
              </View>
            </View>
          )}
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
    flexGrow: 1,
    justifyContent: 'center',
    padding: 24,
  },
  card: {
    backgroundColor: '#ffffff',
    borderRadius: 12,
    padding: 24,
    shadowColor: '#000000',
    shadowOffset: { width: 0, height: 2 },
    shadowOpacity: 0.05,
    shadowRadius: 8,
    elevation: 2,
    borderWidth: 1,
    borderColor: '#e2e8f0',
  },
  brandHeader: {
    alignItems: 'center',
    marginBottom: 20,
  },
  badgeContainer: {
    backgroundColor: '#800000',
    paddingHorizontal: 12,
    paddingVertical: 4,
    borderRadius: 4,
    marginBottom: 8,
  },
  badgeText: {
    color: '#ffffff',
    fontWeight: '700',
    fontSize: 12,
    letterSpacing: 1,
  },
  title: {
    fontSize: 24,
    fontWeight: '800',
    color: '#0f172a',
    letterSpacing: 0.5,
  },
  subtitle: {
    fontSize: 13,
    color: '#64748b',
    marginTop: 2,
  },
  instructionText: {
    fontSize: 13,
    color: '#334155',
    lineHeight: 18,
    marginBottom: 16,
    textAlign: 'center',
  },
  identityBadge: {
    backgroundColor: '#f8fafc',
    borderWidth: 1,
    borderColor: '#e2e8f0',
    borderRadius: 8,
    padding: 12,
    marginBottom: 16,
    alignItems: 'center',
  },
  identityName: {
    fontSize: 15,
    fontWeight: '700',
    color: '#0f172a',
  },
  identityMeta: {
    fontSize: 12,
    fontWeight: '600',
    color: '#800000',
    marginTop: 2,
  },
  identityEmail: {
    fontSize: 11,
    color: '#64748b',
    marginTop: 2,
  },
  demoNotice: {
    backgroundColor: '#fef3c7',
    borderWidth: 1,
    borderColor: '#fcd34d',
    borderRadius: 6,
    padding: 8,
    marginBottom: 16,
    alignItems: 'center',
  },
  demoNoticeText: {
    fontSize: 12,
    color: '#92400e',
    fontWeight: '500',
  },
  demoNoticeCode: {
    fontWeight: '700',
    color: '#800000',
  },
  infoBanner: {
    backgroundColor: '#ecfdf5',
    borderColor: '#a7f3d0',
    borderWidth: 1,
    borderRadius: 6,
    padding: 10,
    marginBottom: 16,
  },
  infoText: {
    color: '#065f46',
    fontSize: 13,
    textAlign: 'center',
  },
  errorBanner: {
    backgroundColor: '#fef2f2',
    borderColor: '#fecaca',
    borderWidth: 1,
    borderRadius: 6,
    padding: 10,
    marginBottom: 16,
  },
  errorText: {
    color: '#dc2626',
    fontSize: 13,
    textAlign: 'center',
  },
  inputGroup: {
    marginBottom: 14,
  },
  label: {
    fontSize: 13,
    fontWeight: '600',
    color: '#0f172a',
    marginBottom: 6,
  },
  input: {
    borderWidth: 1,
    borderColor: '#cbd5e1',
    borderRadius: 6,
    paddingHorizontal: 12,
    paddingVertical: 10,
    fontSize: 14,
    color: '#0f172a',
    backgroundColor: '#ffffff',
  },
  codeInput: {
    fontSize: 20,
    fontWeight: '700',
    textAlign: 'center',
    letterSpacing: 6,
  },
  primaryButton: {
    backgroundColor: '#800000',
    borderRadius: 6,
    paddingVertical: 12,
    alignItems: 'center',
    marginTop: 8,
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
  cancelLinkContainer: {
    marginTop: 16,
    alignItems: 'center',
  },
  secondaryLinkText: {
    fontSize: 13,
    color: '#800000',
    fontWeight: '600',
    textDecorationLine: 'underline',
  },
  subActions: {
    flexDirection: 'row',
    justifyContent: 'center',
    alignItems: 'center',
    marginTop: 16,
    gap: 8,
  },
  subActionLink: {
    fontSize: 12,
    color: '#800000',
    fontWeight: '600',
    textDecorationLine: 'underline',
  },
  subActionDivider: {
    fontSize: 12,
    color: '#94a3b8',
  },
});

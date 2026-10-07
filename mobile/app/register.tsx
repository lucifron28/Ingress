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
import { useAuth } from '../src/context/AuthContext';
import { ApiError } from '../src/services/api-client';

export default function RegisterScreen() {
  const { register } = useAuth();
  const [name, setName] = useState('');
  const [studentId, setStudentId] = useState('');
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [passwordConfirmation, setPasswordConfirmation] = useState('');
  const [isSubmitting, setIsSubmitting] = useState(false);
  const [errorMessage, setErrorMessage] = useState<string | null>(null);

  const handleSubmit = async () => {
    const trimmedName = name.trim();
    const trimmedStudentId = studentId.trim();
    const trimmedEmail = email.trim();

    if (!trimmedName || !trimmedStudentId || !trimmedEmail || !password || !passwordConfirmation) {
      setErrorMessage('Please fill in all fields.');
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
      await register({
        name: trimmedName,
        student_id: trimmedStudentId,
        email: trimmedEmail,
        password,
        password_confirmation: passwordConfirmation,
      });

      router.replace('/(attendee)/events');
    } catch (err: unknown) {
      if (err instanceof ApiError && err.errors) {
        const firstError = Object.values(err.errors)[0]?.[0];
        setErrorMessage(firstError || err.message);
      } else if (err instanceof ApiError) {
        setErrorMessage(err.message);
      } else if (err instanceof Error) {
        setErrorMessage(err.message);
      } else {
        setErrorMessage('Registration failed. Please check your information and try again.');
      }
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
          <View style={styles.brandHeader}>
            <View style={styles.badgeContainer}>
              <Text style={styles.badgeText}>MSEUF</Text>
            </View>
            <Text style={styles.title}>INGRESS</Text>
            <Text style={styles.subtitle}>Student Account Registration</Text>
          </View>

          {errorMessage && (
            <View style={styles.errorBanner}>
              <Text style={styles.errorText}>{errorMessage}</Text>
            </View>
          )}

          <View style={styles.inputGroup}>
            <Text style={styles.label}>Full Name</Text>
            <TextInput
              style={styles.input}
              placeholder="e.g. Maria Clara de los Santos"
              value={name}
              onChangeText={setName}
              editable={!isSubmitting}
            />
          </View>

          <View style={styles.inputGroup}>
            <Text style={styles.label}>Student ID</Text>
            <TextInput
              style={styles.input}
              placeholder="e.g. 2026-00123"
              value={studentId}
              onChangeText={setStudentId}
              autoCapitalize="characters"
              editable={!isSubmitting}
            />
          </View>

          <View style={styles.inputGroup}>
            <Text style={styles.label}>Email</Text>
            <TextInput
              style={styles.input}
              placeholder="e.g. mclara@student.mseuf.edu.ph"
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
              placeholder="Minimum 8 characters"
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
              placeholder="Re-enter password"
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
            onPress={handleSubmit}
            disabled={isSubmitting}
          >
            {isSubmitting ? (
              <ActivityIndicator color="#ffffff" size="small" />
            ) : (
              <Text style={styles.primaryButtonText}>Create Account</Text>
            )}
          </Pressable>

          <View style={styles.loginContainer}>
            <Text style={styles.loginPrompt}>Already have an account?</Text>
            <Pressable onPress={() => router.replace('/login')} hitSlop={8}>
              <Text style={styles.loginLink}>Sign In</Text>
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
    flexGrow: 1,
    justifyContent: 'center',
    padding: 24,
  },
  card: {
    backgroundColor: '#ffffff',
    borderRadius: 16,
    padding: 24,
    shadowColor: '#000',
    shadowOffset: { width: 0, height: 4 },
    shadowOpacity: 0.08,
    shadowRadius: 12,
    elevation: 3,
  },
  brandHeader: {
    alignItems: 'center',
    marginBottom: 20,
  },
  badgeContainer: {
    backgroundColor: '#800000',
    paddingHorizontal: 12,
    paddingVertical: 4,
    borderRadius: 20,
    marginBottom: 8,
  },
  badgeText: {
    color: '#ffffff',
    fontSize: 12,
    fontWeight: '800',
    letterSpacing: 1,
  },
  title: {
    fontSize: 28,
    fontWeight: '900',
    color: '#800000',
    letterSpacing: 2,
  },
  subtitle: {
    fontSize: 13,
    color: '#64748b',
    marginTop: 2,
    fontWeight: '500',
  },
  errorBanner: {
    backgroundColor: '#fef2f2',
    borderWidth: 1,
    borderColor: '#fca5a5',
    borderRadius: 8,
    padding: 12,
    marginBottom: 16,
  },
  errorText: {
    color: '#991b1b',
    fontSize: 13,
    textAlign: 'center',
    fontWeight: '500',
  },
  inputGroup: {
    marginBottom: 14,
  },
  label: {
    fontSize: 13,
    fontWeight: '600',
    color: '#334155',
    marginBottom: 6,
  },
  input: {
    height: 48,
    borderWidth: 1,
    borderColor: '#cbd5e1',
    borderRadius: 8,
    paddingHorizontal: 14,
    fontSize: 15,
    backgroundColor: '#ffffff',
    color: '#0f172a',
  },
  primaryButton: {
    backgroundColor: '#800000',
    height: 48,
    borderRadius: 8,
    justifyContent: 'center',
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
    fontSize: 16,
    fontWeight: '700',
  },
  loginContainer: {
    marginTop: 20,
    alignItems: 'center',
    flexDirection: 'row',
    justifyContent: 'center',
    gap: 6,
  },
  loginPrompt: {
    fontSize: 13,
    color: '#64748b',
  },
  loginLink: {
    fontSize: 13,
    fontWeight: '700',
    color: '#800000',
  },
});

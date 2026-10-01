import { router } from 'expo-router';
import React, { useState } from 'react';
import {
  ActivityIndicator,
  Pressable,
  StyleSheet,
  Text,
  TextInput,
  View,
} from 'react-native';
import { useAuth } from '../src/context/AuthContext';
import { DEFAULT_API_URL } from '../src/services/api-client';

export default function ServerSettingsScreen() {
  const { serverUrl, updateServerUrl } = useAuth();
  const [urlInput, setUrlInput] = useState(serverUrl);
  const [testStatus, setTestStatus] = useState<string | null>(null);
  const [isTesting, setIsTesting] = useState(false);

  const handleTestConnection = async () => {
    setIsTesting(true);
    setTestStatus(null);
    try {
      const cleanUrl = urlInput.trim().replace(/\/+$/, '');
      const controller = new AbortController();
      const timeout = setTimeout(() => controller.abort(), 4000);

      const res = await fetch(`${cleanUrl}/auth/login`, {
        method: 'POST',
        headers: { 'Accept': 'application/json', 'Content-Type': 'application/json' },
        body: JSON.stringify({}),
        signal: controller.signal,
      });

      clearTimeout(timeout);

      // A 422 Unprocessable Content means the server is reachable and active!
      if (res.status === 422 || res.ok) {
        setTestStatus('SUCCESS: Connected to Ingress Event Server');
      } else {
        setTestStatus(`CONNECTED: Server returned HTTP ${res.status}`);
      }
    } catch {
      setTestStatus('FAILED: Could not reach server. Verify LAN connection & firewall.');
    } finally {
      setIsTesting(false);
    }
  };

  const handleSave = async () => {
    const cleanUrl = urlInput.trim().replace(/\/+$/, '');
    await updateServerUrl(cleanUrl);
    router.back();
  };

  const handleReset = () => {
    setUrlInput(DEFAULT_API_URL);
  };

  return (
    <View style={styles.container}>
      <View style={styles.card}>
        <Text style={styles.heading}>LAN Server Configuration</Text>
        <Text style={styles.description}>
          Enter the LAN base URL of the laptop hosting the Ingress Laravel backend.
          Ensure both your phone and laptop are connected to the same Wi-Fi.
        </Text>

        <View style={styles.inputGroup}>
          <Text style={styles.label}>API Base URL</Text>
          <TextInput
            style={styles.input}
            value={urlInput}
            onChangeText={setUrlInput}
            autoCapitalize="none"
            autoCorrect={false}
            placeholder="http://192.168.x.x:8000/api/v1"
          />
        </View>

        {testStatus && (
          <View
            style={[
              styles.statusBanner,
              testStatus.startsWith('SUCCESS') || testStatus.startsWith('CONNECTED')
                ? styles.statusSuccess
                : styles.statusError,
            ]}
          >
            <Text
              style={[
                styles.statusText,
                testStatus.startsWith('SUCCESS') || testStatus.startsWith('CONNECTED')
                  ? styles.statusSuccessText
                  : styles.statusErrorText,
              ]}
            >
              {testStatus}
            </Text>
          </View>
        )}

        <View style={styles.buttonRow}>
          <Pressable
            style={[styles.button, styles.testButton]}
            onPress={handleTestConnection}
            disabled={isTesting}
          >
            {isTesting ? (
              <ActivityIndicator color="#0f172a" size="small" />
            ) : (
              <Text style={styles.testButtonText}>Test Connection</Text>
            )}
          </Pressable>

          <Pressable
            style={[styles.button, styles.saveButton]}
            onPress={handleSave}
          >
            <Text style={styles.saveButtonText}>Save URL</Text>
          </Pressable>
        </View>

        <Pressable style={styles.resetButton} onPress={handleReset}>
          <Text style={styles.resetButtonText}>Reset to Default ({DEFAULT_API_URL})</Text>
        </Pressable>
      </View>
    </View>
  );
}

const styles = StyleSheet.create({
  container: {
    flex: 1,
    backgroundColor: '#f8fafc',
    padding: 20,
    justifyContent: 'center',
  },
  card: {
    backgroundColor: '#ffffff',
    borderRadius: 8,
    borderWidth: 1,
    borderColor: '#e2e8f0',
    padding: 20,
  },
  heading: {
    fontSize: 18,
    fontWeight: '700',
    color: '#0f172a',
    marginBottom: 8,
  },
  description: {
    fontSize: 13,
    color: '#64748b',
    marginBottom: 16,
    lineHeight: 18,
  },
  inputGroup: {
    marginBottom: 16,
  },
  label: {
    fontSize: 12,
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
    fontSize: 13,
    color: '#0f172a',
    fontFamily: 'monospace',
    backgroundColor: '#f8fafc',
  },
  statusBanner: {
    padding: 10,
    borderRadius: 4,
    marginBottom: 16,
  },
  statusSuccess: {
    backgroundColor: '#dcfce7',
    borderLeftWidth: 4,
    borderLeftColor: '#16a34a',
  },
  statusError: {
    backgroundColor: '#fee2e2',
    borderLeftWidth: 4,
    borderLeftColor: '#dc2626',
  },
  statusText: {
    fontSize: 12,
    fontWeight: '600',
  },
  statusSuccessText: {
    color: '#166534',
  },
  statusErrorText: {
    color: '#991b1b',
  },
  buttonRow: {
    flexDirection: 'row',
    gap: 10,
  },
  button: {
    flex: 1,
    height: 42,
    borderRadius: 6,
    justifyContent: 'center',
    alignItems: 'center',
  },
  testButton: {
    backgroundColor: '#f1f5f9',
    borderWidth: 1,
    borderColor: '#cbd5e1',
  },
  testButtonText: {
    color: '#0f172a',
    fontSize: 13,
    fontWeight: '600',
  },
  saveButton: {
    backgroundColor: '#800000',
  },
  saveButtonText: {
    color: '#ffffff',
    fontSize: 13,
    fontWeight: '600',
  },
  resetButton: {
    marginTop: 16,
    alignItems: 'center',
  },
  resetButtonText: {
    fontSize: 11,
    color: '#64748b',
    textDecorationLine: 'underline',
  },
});

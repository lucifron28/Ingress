import { router } from 'expo-router';
import React from 'react';
import {
  Alert,
  Pressable,
  ScrollView,
  StyleSheet,
  Text,
  View,
} from 'react-native';
import { useAuth } from '../../src/context/AuthContext';

export default function ProfileScreen() {
  const { user, serverUrl, logout } = useAuth();

  const handleLogout = () => {
    Alert.alert('Sign Out', 'Are you sure you want to sign out of Ingress?', [
      { text: 'Cancel', style: 'cancel' },
      {
        text: 'Sign Out',
        style: 'destructive',
        onPress: async () => {
          await logout();
          router.replace('/login');
        },
      },
    ]);
  };

  return (
    <ScrollView style={styles.container} contentContainerStyle={styles.contentContainer}>
      <View style={styles.profileCard}>
        <View style={styles.avatar}>
          <Text style={styles.avatarText}>
            {user?.name ? user.name.charAt(0).toUpperCase() : 'U'}
          </Text>
        </View>

        <Text style={styles.name}>{user?.name || 'MSEUF Student'}</Text>
        <Text style={styles.email}>{user?.email}</Text>
        <View style={styles.roleBadge}>
          <Text style={styles.roleBadgeText}>{(user?.role || 'student').toUpperCase()}</Text>
        </View>
      </View>

      <View style={styles.infoCard}>
        <Text style={styles.sectionHeading}>Student Credentials</Text>
        <View style={styles.row}>
          <Text style={styles.label}>Student Number</Text>
          <Text style={styles.valueMono}>{user?.student_id || 'Not Assigned'}</Text>
        </View>
        <View style={styles.row}>
          <Text style={styles.label}>Institution</Text>
          <Text style={styles.value}>Manuel S. Enverga University Foundation</Text>
        </View>
        <View style={styles.row}>
          <Text style={styles.label}>Campus</Text>
          <Text style={styles.value}>Lucena City, Quezon</Text>
        </View>
      </View>

      <View style={styles.infoCard}>
        <Text style={styles.sectionHeading}>LAN Event Server</Text>
        <View style={styles.row}>
          <Text style={styles.label}>Current Host</Text>
          <Text style={styles.valueMono}>{serverUrl}</Text>
        </View>
        <Pressable
          style={styles.settingsButton}
          onPress={() => router.push('/server-settings')}
        >
          <Text style={styles.settingsButtonText}>Configure LAN Server IP</Text>
        </Pressable>
      </View>

      <Pressable style={styles.logoutButton} onPress={handleLogout}>
        <Text style={styles.logoutButtonText}>Sign Out</Text>
      </Pressable>

      <View style={styles.footer}>
        <Text style={styles.footerMotto}>"Pro Deo et Patria" &bull; For God and Country</Text>
        <Text style={styles.footerVersion}>Ingress v1.0 (50% Milestone Build)</Text>
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
    paddingBottom: 40,
  },
  profileCard: {
    backgroundColor: '#ffffff',
    borderRadius: 8,
    borderWidth: 1,
    borderColor: '#e2e8f0',
    borderTopWidth: 4,
    borderTopColor: '#800000',
    padding: 24,
    alignItems: 'center',
    marginBottom: 16,
  },
  avatar: {
    width: 64,
    height: 64,
    borderRadius: 32,
    backgroundColor: '#800000',
    justifyContent: 'center',
    alignItems: 'center',
    marginBottom: 12,
  },
  avatarText: {
    color: '#ffffff',
    fontSize: 24,
    fontWeight: '800',
  },
  name: {
    fontSize: 18,
    fontWeight: '700',
    color: '#0f172a',
    marginBottom: 4,
  },
  email: {
    fontSize: 13,
    color: '#64748b',
    marginBottom: 10,
  },
  roleBadge: {
    backgroundColor: '#fee2e2',
    paddingHorizontal: 10,
    paddingVertical: 3,
    borderRadius: 4,
  },
  roleBadgeText: {
    color: '#800000',
    fontSize: 11,
    fontWeight: '700',
    letterSpacing: 0.5,
  },
  infoCard: {
    backgroundColor: '#ffffff',
    borderRadius: 8,
    borderWidth: 1,
    borderColor: '#e2e8f0',
    padding: 16,
    marginBottom: 16,
  },
  sectionHeading: {
    fontSize: 12,
    fontWeight: '700',
    textTransform: 'uppercase',
    color: '#94a3b8',
    letterSpacing: 0.5,
    marginBottom: 12,
  },
  row: {
    marginBottom: 12,
  },
  label: {
    fontSize: 11,
    color: '#64748b',
    fontWeight: '500',
  },
  value: {
    fontSize: 13,
    color: '#0f172a',
    fontWeight: '600',
    marginTop: 2,
  },
  valueMono: {
    fontSize: 13,
    fontFamily: 'monospace',
    color: '#0f172a',
    fontWeight: '600',
    marginTop: 2,
  },
  settingsButton: {
    backgroundColor: '#f1f5f9',
    borderWidth: 1,
    borderColor: '#cbd5e1',
    borderRadius: 6,
    paddingVertical: 8,
    alignItems: 'center',
    marginTop: 4,
  },
  settingsButtonText: {
    color: '#0f172a',
    fontSize: 13,
    fontWeight: '600',
  },
  logoutButton: {
    backgroundColor: '#ffffff',
    borderWidth: 1,
    borderColor: '#dc2626',
    borderRadius: 6,
    paddingVertical: 12,
    alignItems: 'center',
    marginBottom: 24,
  },
  logoutButtonText: {
    color: '#dc2626',
    fontSize: 14,
    fontWeight: '700',
  },
  footer: {
    alignItems: 'center',
  },
  footerMotto: {
    fontSize: 11,
    color: '#94a3b8',
    fontStyle: 'italic',
    marginBottom: 4,
  },
  footerVersion: {
    fontSize: 10,
    color: '#cbd5e1',
  },
});

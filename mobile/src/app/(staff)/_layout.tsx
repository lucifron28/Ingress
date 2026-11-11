import { Redirect, router, Stack } from 'expo-router';
import React from 'react';
import { Alert, Pressable, StyleSheet, Text, View } from 'react-native';
import { useNodeStaff } from '../../context/NodeStaffContext';

export default function StaffLayout() {
  const node = useNodeStaff();

  if (!node.active) return <Redirect href="/node-staff-login" />;

  const handleLogout = () => {
    Alert.alert('Sign Out', 'Sign out of Staff Scanner mode?', [
      { text: 'Cancel', style: 'cancel' },
      {
        text: 'Sign Out',
        style: 'destructive',
        onPress: async () => {
          try {
            await node.logout();
            router.replace('/node-staff-login');
          } catch {
            Alert.alert('Sign out failed', 'Secure storage is unavailable. Try again.');
          }
        },
      },
    ]);
  };

  return (
    <Stack
      screenOptions={{
        headerStyle: {
          backgroundColor: '#800000',
        },
        headerTintColor: '#ffffff',
        headerTitleStyle: {
          fontWeight: '700',
          fontSize: 17,
        },
        headerRight: () => (
          <View style={styles.headerRight}>
            <View style={styles.staffBadge}>
              <Text style={styles.staffBadgeText}>STAFF</Text>
            </View>
            <Pressable onPress={() => router.push('/node-staff-login')} style={styles.signOutButton}>
              <Text style={styles.signOutText}>Switch node</Text>
            </Pressable>
            <Pressable onPress={handleLogout} style={styles.signOutButton}>
              <Text style={styles.signOutText}>Exit</Text>
            </Pressable>
          </View>
        ),
      }}
    >
      <Stack.Screen
        name="events"
        options={{
          title: 'Assigned Events',
        }}
      />
      <Stack.Screen
        name="scan"
        options={{
          title: 'Ticket Scanner',
          headerBackTitle: 'Events',
        }}
      />
      <Stack.Screen name="walk-in" options={{ title: 'Register walk-in', headerBackTitle: 'Events' }} />
    </Stack>
  );
}

const styles = StyleSheet.create({
  headerRight: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: 8,
  },
  staffBadge: {
    backgroundColor: '#c5a059',
    paddingHorizontal: 6,
    paddingVertical: 2,
    borderRadius: 3,
  },
  staffBadgeText: {
    color: '#0f172a',
    fontSize: 10,
    fontWeight: '800',
  },
  signOutButton: {
    paddingHorizontal: 8,
    paddingVertical: 4,
    borderRadius: 4,
    borderWidth: 1,
    borderColor: 'rgba(255, 255, 255, 0.4)',
  },
  signOutText: {
    color: '#ffffff',
    fontSize: 12,
    fontWeight: '600',
  },
});

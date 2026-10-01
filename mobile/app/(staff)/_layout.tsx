import { router, Stack } from 'expo-router';
import React from 'react';
import { Alert, Pressable, StyleSheet, Text, View } from 'react-native';
import { useAuth } from '../../src/context/AuthContext';

export default function StaffLayout() {
  const { user, logout } = useAuth();

  const handleLogout = () => {
    Alert.alert('Sign Out', 'Sign out of Staff Scanner mode?', [
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

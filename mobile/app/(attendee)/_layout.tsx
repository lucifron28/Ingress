import { Tabs } from 'expo-router';
import React from 'react';
import { StyleSheet, Text, View } from 'react-native';

export default function AttendeeLayout() {
  return (
    <Tabs
      screenOptions={{
        headerStyle: {
          backgroundColor: '#800000',
        },
        headerTintColor: '#ffffff',
        headerTitleStyle: {
          fontWeight: '700',
          fontSize: 18,
        },
        tabBarActiveTintColor: '#800000',
        tabBarInactiveTintColor: '#64748b',
        tabBarStyle: {
          backgroundColor: '#ffffff',
          borderTopColor: '#e2e8f0',
          height: 60,
          paddingBottom: 8,
          paddingTop: 8,
        },
        tabBarLabelStyle: {
          fontSize: 12,
          fontWeight: '600',
        },
      }}
    >
      <Tabs.Screen
        name="events/index"
        options={{
          title: 'Discover Events',
          tabBarLabel: 'Discover',
          tabBarIcon: ({ color }) => (
            <Text style={{ color, fontSize: 16, fontWeight: '700' }}>[E]</Text>
          ),
        }}
      />
      <Tabs.Screen
        name="events/[id]"
        options={{
          href: null, // Hide from tab bar, accessible via navigation
          title: 'Event Details',
        }}
      />
      <Tabs.Screen
        name="tickets/index"
        options={{
          title: 'My Tickets',
          tabBarLabel: 'My Tickets',
          tabBarIcon: ({ color }) => (
            <Text style={{ color, fontSize: 16, fontWeight: '700' }}>[T]</Text>
          ),
        }}
      />
      <Tabs.Screen
        name="tickets/[id]"
        options={{
          href: null,
          title: 'Digital Ticket',
        }}
      />
      <Tabs.Screen
        name="profile"
        options={{
          title: 'Student Profile',
          tabBarLabel: 'Profile',
          tabBarIcon: ({ color }) => (
            <Text style={{ color, fontSize: 16, fontWeight: '700' }}>[P]</Text>
          ),
        }}
      />
    </Tabs>
  );
}

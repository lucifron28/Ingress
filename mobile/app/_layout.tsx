import { Stack } from 'expo-router';
import { StatusBar } from 'expo-status-bar';
import React from 'react';
import { AuthProvider } from '../src/context/AuthContext';
import { NodeStaffProvider } from '../src/context/NodeStaffContext';

export default function RootLayout() {
  return (
    <NodeStaffProvider>
      <AuthProvider>
        <StatusBar style="light" />
        <Stack
          screenOptions={{
            headerStyle: {
              backgroundColor: '#800000',
            },
            headerTintColor: '#ffffff',
            headerTitleStyle: {
              fontWeight: '700',
            },
          }}
        >
          <Stack.Screen name="index" options={{ headerShown: false }} />
          <Stack.Screen name="login" options={{ title: 'Sign In - Ingress' }} />
          <Stack.Screen name="node-staff-login" options={{ title: 'Staff scanner sign in' }} />
          <Stack.Screen name="student-register" options={{ title: 'Student account' }} />
          <Stack.Screen name="(attendee)" options={{ headerShown: false }} />
          <Stack.Screen name="(staff)" options={{ headerShown: false }} />
          <Stack.Screen name="server-settings" options={{ title: 'LAN Server Settings' }} />
        </Stack>
      </AuthProvider>
    </NodeStaffProvider>
  );
}

import { Stack } from 'expo-router';
import { StatusBar } from 'expo-status-bar';
import React from 'react';
import { NodeStaffProvider } from '../context/NodeStaffContext';

export default function RootLayout() {
  return (
    <NodeStaffProvider>
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
        <Stack.Screen name="node-staff-login" options={{ title: 'Staff scanner sign in' }} />
        <Stack.Screen name="(staff)" options={{ headerShown: false }} />
      </Stack>
    </NodeStaffProvider>
  );
}

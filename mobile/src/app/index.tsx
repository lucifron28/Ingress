import { Redirect } from 'expo-router';
import React from 'react';
import { useNodeStaff } from '../context/NodeStaffContext';

export default function Index() {
  const node = useNodeStaff();
  return <Redirect href={node.active ? '/(staff)/events' : '/node-staff-login'} />;
}

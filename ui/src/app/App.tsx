import type React from 'react';
import { Outlet } from 'react-router-dom';
import ErrorBoundary from '@/components/error/ErrorBoundary';
import Topbar from '@/components/topbar/Topbar';
import useSyncComponentId from '@/hooks/useSyncComponentId';
import Layout from '@/features/layout/Layout';

const App: React.FC = () => {
  // Hook to keep the selected component ID in state in sync with :componentId in the url params.
  useSyncComponentId();

  return (
    <div className="xb-app">
      <ErrorBoundary
        variant="alert"
        title="An unexpected error has occurred while fetching layouts."
      >
        <Layout />
      </ErrorBoundary>
      <ErrorBoundary variant="page">
        <Outlet />
      </ErrorBoundary>
      <Topbar />
    </div>
  );
};

export default App;

import type React from 'react';
import Layout from '@/features/layout/Layout';
import { Outlet } from 'react-router-dom';
import Canvas from '@/features/canvas/Canvas';
import ErrorBoundary from '@/components/error/ErrorBoundary';
import PrimaryPanel from '@/components/sidebar/PrimaryPanel';
import Topbar from '@/components/topbar/Topbar';
import useSyncComponentId from '@/hooks/useSyncComponentId';
import ZoomControl from '@/components/zoom/ZoomControl';

const App: React.FC = () => {
  // Hook to keep the selected component ID in state in sync with :componentId in the url params.
  useSyncComponentId();

  return (
    <div className="xb-app">
      <ErrorBoundary variant="page">
        <Canvas />
        <ErrorBoundary
          variant="alert"
          title="An unexpected error has occurred while fetching layouts."
        >
          <Layout />
        </ErrorBoundary>
        <Topbar />
        <PrimaryPanel />
        <Outlet />
        <ZoomControl />
        <div id="menuBarContainer"></div>
        <div id="menuBarSubmenuContainer"></div>
      </ErrorBoundary>
    </div>
  );
};

export default App;

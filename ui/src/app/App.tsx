import type React from 'react';
import { Outlet } from 'react-router-dom';
import ErrorBoundary from '@/components/error/ErrorBoundary';
import Topbar from '@/components/topbar/Topbar';
import Layout from '@/features/layout/Layout';

const App: React.FC = () => {
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

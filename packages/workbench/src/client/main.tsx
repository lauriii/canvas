import React, { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';
import { BrowserRouter, Navigate, Route, Routes } from 'react-router';

import './index.css';

import App from './App';
import { ThemeProvider } from './components/theme-provider';
import { Toaster } from './components/ui/sonner';

type WorkbenchWindow = Window & {
  React?: typeof React;
  drupalSettings?: {
    canvasData?: {
      v0?: {
        baseUrl?: string;
      };
    };
  };
};

function ensurePreviewRuntimeGlobals(): void {
  const runtimeWindow = window as WorkbenchWindow;
  runtimeWindow.React ??= React;
  runtimeWindow.drupalSettings ??= {};
  runtimeWindow.drupalSettings.canvasData ??= {};
  runtimeWindow.drupalSettings.canvasData.v0 ??= {};

  if (
    typeof runtimeWindow.drupalSettings.canvasData.v0.baseUrl !== 'string' ||
    runtimeWindow.drupalSettings.canvasData.v0.baseUrl.length === 0
  ) {
    runtimeWindow.drupalSettings.canvasData.v0.baseUrl =
      runtimeWindow.location.origin;
  }
}

ensurePreviewRuntimeGlobals();
const defaultWorkbenchRoute = '/page';

createRoot(document.getElementById('root')!).render(
  <StrictMode>
    <ThemeProvider defaultTheme="system" storageKey="canvas-workbench-theme">
      <BrowserRouter>
        <Routes>
          <Route
            path="/"
            element={<Navigate to={defaultWorkbenchRoute} replace />}
          />
          <Route path="/component" element={<App />} />
          <Route path="/component/:componentId" element={<App />} />
          <Route path="/component/:componentId/:mockIndex" element={<App />} />
          <Route path="/page" element={<App />} />
          <Route path="/page/:slug" element={<App />} />
          <Route
            path="*"
            element={<Navigate to={defaultWorkbenchRoute} replace />}
          />
        </Routes>
      </BrowserRouter>
      <Toaster closeButton richColors position="bottom-right" />
    </ThemeProvider>
  </StrictMode>,
);

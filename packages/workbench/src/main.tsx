import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';
import { BrowserRouter, Navigate, Route, Routes } from 'react-router';

import './index.css';

import App from './App';
import PreviewFrameApp from './PreviewFrameApp';

const isPreviewFrameRoute =
  window.location.pathname === '/__canvas/preview-frame';

createRoot(document.getElementById('root')!).render(
  <StrictMode>
    {isPreviewFrameRoute ? (
      <PreviewFrameApp />
    ) : (
      <BrowserRouter>
        <Routes>
          <Route path="/" element={<Navigate to="/component" replace />} />
          <Route path="/component" element={<App />} />
          <Route path="/component/:componentId" element={<App />} />
          <Route path="/page" element={<App />} />
          <Route path="/page/:slug" element={<App />} />
          <Route path="*" element={<Navigate to="/component" replace />} />
        </Routes>
      </BrowserRouter>
    )}
  </StrictMode>,
);

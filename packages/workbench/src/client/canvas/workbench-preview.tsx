import { StrictMode } from 'react';
import { declareCanvasRuntime } from 'drupal-canvas';
import { createRoot } from 'react-dom/client';
import { MemoryRouter } from 'react-router';

import '../index.css';

import PreviewFrameApp from '../PreviewFrameApp';

// Workbench previews support the legacy runtime APIs (`getPageData()`,
// `getSiteData()`, `new JsonApiClient()`). Declare the environment before any
// Code Component module is loaded.
declareCanvasRuntime('workbench');

createRoot(document.getElementById('root')!).render(
  <StrictMode>
    <MemoryRouter initialEntries={['/page']}>
      <PreviewFrameApp />
    </MemoryRouter>
  </StrictMode>,
);

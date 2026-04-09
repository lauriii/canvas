import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';
import { MemoryRouter } from 'react-router';

import '../index.css';

import PreviewFrameApp from '../PreviewFrameApp';

createRoot(document.getElementById('root')!).render(
  <StrictMode>
    <MemoryRouter initialEntries={['/page']}>
      <PreviewFrameApp />
    </MemoryRouter>
  </StrictMode>,
);

import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';

import '../index.css';

import PreviewFrameApp from '../PreviewFrameApp';

createRoot(document.getElementById('root')!).render(
  <StrictMode>
    <PreviewFrameApp />
  </StrictMode>,
);

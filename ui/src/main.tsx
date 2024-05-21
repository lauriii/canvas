import React from 'react';
import { createRoot } from 'react-dom/client';
import { Provider } from 'react-redux';
import App from './App';
import { store } from './app/store';
import './index.css';
import { makeServer } from './server';
import '@radix-ui/themes/styles.css';
import { Theme, ThemePanel } from '@radix-ui/themes';

// TODO how do we do this in Drupal?
const ENV = 'development';

if (ENV === 'development') {
  makeServer({ environment: 'development' });
}

const container = document.getElementById('experience-builder');

if (container) {
  const root = createRoot(container);

  root.render(
    <React.StrictMode>
      <Theme hasBackground={false} panelBackground="solid" appearance="dark">
        <ThemePanel />
        <Provider store={store}>
          <App />
        </Provider>
      </Theme>
    </React.StrictMode>,
  );
} else {
  throw new Error(
    "Root element with ID 'root' was not found in the document. Ensure there is a corresponding HTML element with the ID 'root' in your HTML file.",
  );
}

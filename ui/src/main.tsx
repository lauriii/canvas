import React from 'react';
import { createRoot } from 'react-dom/client';
import { Provider } from 'react-redux';
import AppRoutes from '@/app/AppRoutes';
import { makeStore } from '@/app/store';
import './index.css';
import '@radix-ui/themes/styles.css';
import { Theme } from '@radix-ui/themes';
import type { AppConfiguration } from '@/features/configuration/configurationSlice';

const { drupalSettings } = window as any;

const prepare = async () => {
  if (
    import.meta.env.VITE_DRUPAL !== 'true' &&
    (!process.env.NODE_ENV || process.env.NODE_ENV === 'development')
  ) {
    const { worker } = await import('./mocks/browser');
    return worker.start();
  }

  return Promise.resolve();
};

const container = document.getElementById('experience-builder');

// Here we will pass along app configuration such as entity-type and ID.
// We will have access to `window.drupalSettings` here.
const appConfiguration: AppConfiguration = {
  baseUrl: drupalSettings.path.baseUrl || import.meta.env.BASE_URL,
};

if (container) {
  prepare().then(() => {
    const root = createRoot(container);
    root.render(
      <React.StrictMode>
        <Theme hasBackground={false} panelBackground="solid" appearance="light">
          <Provider store={makeStore({ configuration: appConfiguration })}>
            <AppRoutes />
          </Provider>
        </Theme>
      </React.StrictMode>,
    );
  });
} else {
  throw new Error(
    "Root element with ID 'root' was not found in the document. Ensure there is a corresponding HTML element with the ID 'root' in your HTML file.",
  );
}

import React from 'react';
import { createRoot } from 'react-dom/client';
import { Provider } from 'react-redux';
import AppRoutes from '@/app/AppRoutes';
import { makeStore } from '@/app/store';
import '@radix-ui/themes/styles.css';
import { Theme } from '@radix-ui/themes';
import type { AppConfiguration } from '@/features/configuration/configurationSlice';
import './index.css';

interface XbSettings {
  path: { baseUrl: string };
  xb: {
    base: string;
    entityType: string;
    entity: string;
  };
}

interface XbGlobals {
  drupalSettings?: XbSettings;
}

const { drupalSettings } = window as XbGlobals;

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

const appConfiguration: AppConfiguration = {
  baseUrl: drupalSettings?.path?.baseUrl || import.meta.env.BASE_URL,
  entityType: drupalSettings?.xb?.entityType || 'node',
  entity: drupalSettings?.xb?.entity || '1',
};

if (container) {
  prepare().then(() => {
    const root = createRoot(container);
    let routerRoot = appConfiguration.baseUrl;
    if (drupalSettings?.xb?.base) {
      routerRoot = `${routerRoot}${drupalSettings.xb.base}`;
    }
    root.render(
      <React.StrictMode>
        <Theme
          accentColor="blue"
          hasBackground={false}
          panelBackground="solid"
          appearance="light"
        >
          <Provider store={makeStore({ configuration: appConfiguration })}>
            <AppRoutes basePath={routerRoot} />
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

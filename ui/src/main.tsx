import React from 'react';
import type { FC, ReactHTMLElement } from 'react';
import { createRoot } from 'react-dom/client';
import { Provider } from 'react-redux';
import AppRoutes from '@/app/AppRoutes';
import { makeStore } from '@/app/store';
import { Theme } from '@radix-ui/themes';
import ErrorBoundary from '@/components/error/ErrorBoundary';
import type { AppConfiguration } from '@/features/configuration/configurationSlice';
import twigToJSXComponentMap from '@/components/form/twig-to-jsx-component-map';
import hyperscriptify from '@/local_packages/hyperscriptify';
import propsify from '@/local_packages/hyperscriptify/propsify/standard';
import type { EnhancedStore } from '@reduxjs/toolkit';

import '@/styles/radix-themes';
import '@/styles/index.css';

interface XbSettings {
  path: { baseUrl: string };
  xb: {
    base: string;
    entityType: string;
    entity: string;
  };
}

interface ProviderComponentProps {
  store: EnhancedStore;
}

interface XbGlobals {
  drupalSettings?: XbSettings;
}

const { drupalSettings } = window as XbGlobals;
const { Drupal } = window as any;

const container = document.getElementById('experience-builder');

const appConfiguration: AppConfiguration = {
  baseUrl: drupalSettings?.path?.baseUrl || import.meta.env.BASE_URL,
  entityType: drupalSettings?.xb?.entityType || 'node',
  entity: drupalSettings?.xb?.entity || '1',
};

if (container) {
  const root = createRoot(container);
  let routerRoot = appConfiguration.baseUrl;
  if (drupalSettings?.xb?.base) {
    routerRoot = `${routerRoot}${drupalSettings.xb.base}`;
  }
  const store = makeStore({ configuration: appConfiguration });
  root.render(
    <React.StrictMode>
      <Theme
        accentColor="blue"
        hasBackground={false}
        panelBackground="solid"
        appearance="light"
      >
        <ErrorBoundary variant="page">
          <Provider store={store}>
            <AppRoutes basePath={routerRoot} />
          </Provider>
        </ErrorBoundary>
      </Theme>
    </React.StrictMode>,
  );

  // Make the list of twig-to-JSX components available to Drupal behaviors.
  Drupal.JSXComponents = twigToJSXComponentMap;

  // Make this application's hyperscriptify functionality available to
  // Drupal behaviors.
  Drupal.Hyperscriptify = (context: HTMLElement) => {
    return hyperscriptify(
      context,
      React.createElement,
      React.Fragment,
      twigToJSXComponentMap,
      { propsify },
    );
  };

  // Provide Drupal behaviors this method for hyperscriptifying content added
  // via the Drupal AJAX API.
  Drupal.HyperscriptifyAdditional = (
    Application: ReactHTMLElement<any>,
    context: HTMLElement,
  ) => {
    const container = document.createElement('div');
    context.after(container);
    const root = createRoot(container);

    // Wrap the newly rendered content in the Redux provider so it has access
    // to the existing store.
    root.render(
      React.createElement<ProviderComponentProps>(
        Provider as FC,
        { store },
        Application as ReactHTMLElement<any>,
      ),
    );
    return container;
  };
} else {
  throw new Error(
    "Root element with ID 'root' was not found in the document. Ensure there is a corresponding HTML element with the ID 'root' in your HTML file.",
  );
}

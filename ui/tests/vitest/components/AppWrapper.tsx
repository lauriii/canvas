import { Provider } from 'react-redux';
import { MemoryRouter, Route, Routes } from 'react-router-dom';
import { Theme } from '@radix-ui/themes';

import ErrorBoundary from '@/components/error/ErrorBoundary';

import type { PropsWithChildren } from 'react';
import type { AppStore } from '@/app/store';

const AppWrapper = ({
  store,
  location,
  path,
  children,
}: PropsWithChildren<{
  store: AppStore;
  location: string;
  path: string;
}>) => (
  <MemoryRouter
    initialEntries={[location]}
    future={{ v7_relativeSplatPath: true, v7_startTransition: true }} // Avoid React Router future warnings.
  >
    <Routes>
      <Route
        path={path}
        element={
          <Provider store={store}>
            <Theme
              accentColor="blue"
              hasBackground={false}
              panelBackground="solid"
              appearance="light"
            >
              <ErrorBoundary title="An unexpected error has occurred in the code editor.">
                {children}
              </ErrorBoundary>
            </Theme>
          </Provider>
        }
      />
    </Routes>
  </MemoryRouter>
);

export default AppWrapper;

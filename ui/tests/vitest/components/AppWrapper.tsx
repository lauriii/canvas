import { Provider } from 'react-redux';
import { Theme } from '@radix-ui/themes';
import { MemoryRouter, Route, Routes } from 'react-router-dom';
import ErrorBoundary from '@/components/error/ErrorBoundary';
import { DndProvider } from 'react-dnd';
import { HTML5toTouch } from 'rdndmb-html5-to-touch';
import { MultiBackend } from 'react-dnd-multi-backend';
import type { AppStore } from '@/app/store';
import type { PropsWithChildren } from 'react';

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
                <DndProvider backend={MultiBackend} options={HTML5toTouch}>
                  {children}
                </DndProvider>
              </ErrorBoundary>
            </Theme>
          </Provider>
        }
      />
    </Routes>
  </MemoryRouter>
);

export default AppWrapper;

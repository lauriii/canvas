import { createContext, useContext } from 'react';
import { useParams } from 'react-router';

import type { ReactNode } from 'react';

const SelectedCodeComponentContext = createContext<string | undefined>(
  undefined,
);

/**
 * Provides the machine name of the code component open in the code editor.
 *
 * Code component items in the Library and Code panels show that component as
 * selected. useParams() re-renders its caller on every navigation, so the
 * provider reads the route once. An item that calls
 * useSelectedCodeComponent() re-renders only when the machine name changes.
 */
export const SelectedCodeComponentProvider = ({
  children,
}: {
  children: ReactNode;
}) => {
  const { codeComponentId } = useParams();
  return (
    <SelectedCodeComponentContext.Provider value={codeComponentId}>
      {children}
    </SelectedCodeComponentContext.Provider>
  );
};

/**
 * Returns the machine name of the code component open in the code editor.
 *
 * Returns undefined outside a SelectedCodeComponentProvider.
 */
export const useSelectedCodeComponent = (): string | undefined =>
  useContext(SelectedCodeComponentContext);

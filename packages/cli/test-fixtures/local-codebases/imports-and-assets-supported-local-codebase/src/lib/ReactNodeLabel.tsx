import type { ReactNode } from 'react';

interface ReactNodeLabelProps {
  children: ReactNode;
}

export function ReactNodeLabel({ children }: ReactNodeLabelProps) {
  return <span>{children}</span>;
}

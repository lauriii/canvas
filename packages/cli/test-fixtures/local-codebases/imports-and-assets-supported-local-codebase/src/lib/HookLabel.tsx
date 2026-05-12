import { useId } from 'react';

interface HookLabelProps {
  children: string;
}

export function HookLabel({ children }: HookLabelProps) {
  const id = useId();

  return <span id={id}>{children.trim()}</span>;
}

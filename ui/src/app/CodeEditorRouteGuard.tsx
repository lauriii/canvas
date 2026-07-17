import { Navigate } from 'react-router-dom';

import { useCanvasHeadlessSettings } from '@/hooks/useCanvasHeadlessSettings';

import type { PropsWithChildren } from 'react';

const CodeEditorRouteGuard = ({ children }: PropsWithChildren) => {
  const headlessSettings = useCanvasHeadlessSettings();
  const hasConfiguredFrontend = (headlessSettings?.frontends.length ?? 0) > 0;

  return hasConfiguredFrontend ? <Navigate to="/" replace /> : children;
};

export default CodeEditorRouteGuard;

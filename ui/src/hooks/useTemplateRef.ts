import { useLocation } from 'react-router';

import { useAppSelector } from '@/app/hooks';
import {
  EditorFrameContext,
  selectEditorFrameContext,
} from '@/features/ui/uiSlice';

/**
 * Provides template and preview context derived from the current URL and Redux state.
 */
export const useTemplateRef = () => {
  const { pathname } = useLocation();
  const editorFrameContext = useAppSelector(selectEditorFrameContext);
  // Also while the template editor shows a template in another language than
  // its own, which sets no editor frame context.
  const isTemplateContext =
    editorFrameContext === EditorFrameContext.TEMPLATE ||
    pathname.startsWith('/template/');
  const isTemplatePreviewRoute = pathname.startsWith('/preview/template');

  return { isTemplateContext, isTemplatePreviewRoute };
};

import { useLocation } from 'react-router-dom';

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
  const isTemplateContext = editorFrameContext === EditorFrameContext.TEMPLATE;
  const isTemplatePreviewRoute = pathname.startsWith('/preview/template');

  return { isTemplateContext, isTemplatePreviewRoute };
};

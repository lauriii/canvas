import { useAppSelector } from '@/app/hooks';
import {
  EditorFrameContext,
  selectEditorFrameContext,
} from '@/features/ui/uiSlice';
import { getCanvasHeadlessSettings } from '@/utils/drupal-globals';

import type { HeadlessSettings } from '@drupal-canvas/types';

/**
 * The headless settings when the editor frame should embed the frontend app.
 *
 * Returns the canvas_headless module's settings only in an entity editing
 * context: content templates are config entities without a public path for
 * the app to enter at, so the template editor keeps the Drupal-rendered
 * srcdoc preview. Returns undefined otherwise. Both the preview branch and
 * the overlay suppression read this one value, so they cannot disagree about
 * whether headless mode is on.
 */
export function useHeadlessPreviewSettings(): HeadlessSettings | undefined {
  const editorFrameContext = useAppSelector(selectEditorFrameContext);
  const settings = getCanvasHeadlessSettings();
  if (!settings || editorFrameContext === EditorFrameContext.TEMPLATE) {
    return undefined;
  }
  return settings;
}

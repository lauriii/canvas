import { createListenerMiddleware, isAnyOf } from '@reduxjs/toolkit';

import {
  deleteNode,
  moveNode,
  selectLayout,
  shiftNode,
} from '@/features/layout/layoutModelSlice';
import {
  getPreviewDocument,
  movePreviewComponent,
  removePreviewComponent,
} from '@/features/layout/preview/optimisticPreview';

import type { StateWithHistoryWrapper } from '@/features/layout/layoutModelSlice';

/**
 * Shows moves and deletions in the preview as soon as they are made.
 *
 * Whatever makes them (drags, menus, the toolbar, hotkeys), the preview shows
 * them right away instead of after the server has rendered a new preview.
 * Components added from the library are shown by their drop handler, which
 * has their default markup.
 */
export const optimisticPreviewListener = createListenerMiddleware();

optimisticPreviewListener.startListening({
  matcher: isAnyOf(moveNode, shiftNode),
  effect: (action, { getState }) => {
    const previewDocument = getPreviewDocument();
    if (previewDocument) {
      movePreviewComponent(
        previewDocument,
        selectLayout(getState() as StateWithHistoryWrapper),
        (action.payload as { uuid: string }).uuid,
      );
    }
  },
});

optimisticPreviewListener.startListening({
  actionCreator: deleteNode,
  effect: (action) => {
    const previewDocument = getPreviewDocument();
    if (previewDocument) {
      removePreviewComponent(previewDocument, action.payload);
    }
  },
});

import { v4 as uuidv4 } from 'uuid';

import { useAppDispatch, useAppStore } from '@/app/hooks';
import {
  _addNewComponentToLayout,
  addNewPatternToLayout,
  selectLayout,
} from '@/features/layout/layoutModelSlice';
import { commitLivePreview } from '@/features/layout/preview/livePreview';
import {
  getPreviewDocument,
  insertPreviewComponent,
  movePreviewComponent,
} from '@/features/layout/preview/optimisticPreview';
import useComponentSelection from '@/hooks/useComponentSelection';

import type { DragEndEvent } from '@dnd-kit/core';
import type { Pattern } from '@/types/Pattern';

export function useDropFromLibraryHandler() {
  const dispatch = useAppDispatch();
  const store = useAppStore();
  const { setSelectedComponent } = useComponentSelection();

  function handleNewDrop(event: DragEndEvent) {
    const newItem = event.active.data?.current?.item;
    const dropPath = event.over?.data?.current?.path;
    if (!dropPath) {
      // The component we are dropping onto was not found. I don't think this can happen, but if it does, do nothing.
      return;
    }
    const type = event.active.data?.current?.type;
    if (type === 'component' || type === 'dynamicComponent') {
      const uuid = uuidv4();
      dispatch(
        _addNewComponentToLayout(
          {
            to: dropPath,
            component: newItem,
            predefinedUUID: uuid,
          },
          setSelectedComponent,
        ),
      );
      // Show the component right away instead of after the new preview is
      // rendered: keep the markup the live preview shows, or insert it.
      const layout = selectLayout(store.getState());
      const livePreviewDocument = commitLivePreview(uuid);
      if (livePreviewDocument) {
        // The live preview added the CSS when the drag started.
        movePreviewComponent(livePreviewDocument, layout, uuid);
      } else {
        const previewDocument = getPreviewDocument();
        if (previewDocument && newItem.default_markup) {
          insertPreviewComponent(previewDocument, layout, uuid, newItem);
        }
      }
    } else if (type === 'pattern') {
      // The markup of a pattern isn't kept: its markers aren't those of the
      // components the pattern adds. The new preview shows them.
      dispatch(
        addNewPatternToLayout(
          {
            to: dropPath,
            layoutModel: (newItem as Pattern).layoutModel,
          },
          setSelectedComponent,
        ),
      );
    }
  }

  return { handleNewDrop };
}

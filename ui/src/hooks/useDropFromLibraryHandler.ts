import { v4 as uuidv4 } from 'uuid';

import { useAppDispatch, useAppStore } from '@/app/hooks';
import {
  _addNewComponentToLayout,
  addNewPatternToLayout,
  selectLayout,
} from '@/features/layout/layoutModelSlice';
import {
  getPreviewDocument,
  insertPreviewComponent,
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
      // rendered.
      const previewDocument = getPreviewDocument();
      if (previewDocument && newItem.default_markup) {
        insertPreviewComponent(
          previewDocument,
          selectLayout(store.getState()),
          uuid,
          newItem,
        );
      }
    } else if (type === 'pattern') {
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

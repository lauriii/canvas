import { useAppDispatch } from '@/app/hooks';
import {
  _addNewComponentToLayout,
  addNewPatternToLayout,
} from '@/features/layout/layoutModelSlice';
import useComponentSelection from '@/hooks/useComponentSelection';

import type { DragEndEvent } from '@dnd-kit/core';
import type { Pattern } from '@/types/Pattern';

export function useDropFromLibraryHandler() {
  const dispatch = useAppDispatch();
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
      // @todo We should optimistically insert newItem.default_markup into to the new location in the iFrames dom.
      dispatch(
        _addNewComponentToLayout(
          {
            to: dropPath,
            component: newItem,
          },
          setSelectedComponent,
        ),
      );
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

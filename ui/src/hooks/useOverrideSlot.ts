import { useCallback } from 'react';

import { useAppDispatch, useAppStore } from '@/app/hooks';
import { filterNonMarkerComponents } from '@/features/layout/exposedSlots';
import {
  overrideSlotDefaultContent,
  selectLayout,
} from '@/features/layout/layoutModelSlice';
import useComponentSelection from '@/hooks/useComponentSelection';

/**
 * Per-content editing: overrides an inherited exposed slot.
 *
 * The inherited slot is selected as a whole, and that selection has no
 * settings once the slot is overridden. So after the override, the first
 * forked component is selected, which shows its settings. An empty override
 * clears the selection.
 */
export function useOverrideSlot() {
  const dispatch = useAppDispatch();
  const store = useAppStore();
  const { setSelectedComponent, unsetSelectedComponent } =
    useComponentSelection();

  return useCallback(
    (fieldName: string) => {
      dispatch(overrideSlotDefaultContent(fieldName));
      const layout = selectLayout(store.getState());
      const region = layout.find((r) => r.id === fieldName);
      const [first] = region
        ? filterNonMarkerComponents(region.components)
        : [];
      if (first) {
        setSelectedComponent(first.uuid, layout);
      } else {
        unsetSelectedComponent();
      }
    },
    [dispatch, store, setSelectedComponent, unsetSelectedComponent],
  );
}

export default useOverrideSlot;

import { useCallback } from 'react';
import { v4 as uuidv4 } from 'uuid';

import { useAppDispatch, useAppSelector } from '@/app/hooks';
import {
  deleteNode,
  duplicateNode,
  selectLayout,
} from '@/features/layout/layoutModelSlice';
import {
  areConsecutiveSiblings,
  sortUuidsByDocumentOrder,
} from '@/features/layout/layoutUtils';
import { setDialogOpen } from '@/features/ui/dialogSlice';
import { selectSelection } from '@/features/ui/uiSlice';
import useComponentSelection from '@/hooks/useComponentSelection';
import useCopyPasteComponents from '@/hooks/useCopyPasteComponents';

import type { AppThunk } from '@/app/store';

/**
 * Operations acting on the current selection, single or multi.
 *
 * Each operation reads `selection.items` from the store (not the URL), sorts
 * it into document order where order matters, performs the whole batch as one
 * dispatch (one undo entry), and updates the selection afterwards: delete
 * clears it, duplicate selects the duplicates, paste selects the pasted
 * components. Group-producing operations (copy, duplicate, save as pattern)
 * require the selection to be consecutive siblings; delete does not.
 */
export function useMultiSelectionOperations() {
  const dispatch = useAppDispatch();
  const layout = useAppSelector(selectLayout);
  const selection = useAppSelector(selectSelection);
  const { unsetSelectedComponent, updateSelectionInRedux } =
    useComponentSelection();
  const { copySelectedComponent, pasteAfterSelectedComponent } =
    useCopyPasteComponents();

  const deleteSelection = useCallback(() => {
    // The selection can go stale when undo/redo rewrites the layout under it,
    // so operate only on the components that still exist.
    const existingUuids = sortUuidsByDocumentOrder(layout, selection.items);
    if (!existingUuids.length) {
      return;
    }
    dispatch(deleteNode(existingUuids));
    unsetSelectedComponent();
  }, [dispatch, layout, selection.items, unsetSelectedComponent]);

  const copySelection = useCallback(() => {
    copySelectedComponent();
  }, [copySelectedComponent]);

  const pasteAfterSelection = useCallback(() => {
    pasteAfterSelectedComponent();
  }, [pasteAfterSelectedComponent]);

  const duplicateSelection = useCallback(() => {
    const sortedUuids = sortUuidsByDocumentOrder(layout, selection.items);
    // Re-validate against the current layout rather than trusting the stored
    // `consecutive` flag: undo/redo can rewrite the layout under a selection,
    // removing selected components or breaking their adjacency.
    if (
      !sortedUuids.length ||
      sortedUuids.length !== selection.items.length ||
      !areConsecutiveSiblings(layout, sortedUuids)
    ) {
      return;
    }
    // Pre-assign UUIDs so the duplicates can become the selection.
    const useUUIDs = sortedUuids.map(() => uuidv4());
    dispatch(duplicateNode({ uuid: sortedUuids, useUUIDs }));
    // Select the duplicates using the post-duplicate layout, so the
    // consecutive-siblings status of the new selection is computed correctly.
    const selectDuplicates: AppThunk = (_dispatch, getState) => {
      updateSelectionInRedux(useUUIDs, selectLayout(getState()));
    };
    dispatch(selectDuplicates);
  }, [dispatch, layout, selection.items, updateSelectionInRedux]);

  const saveSelectionAsPattern = useCallback(() => {
    const sortedUuids = sortUuidsByDocumentOrder(layout, selection.items);
    if (
      !sortedUuids.length ||
      sortedUuids.length !== selection.items.length ||
      !areConsecutiveSiblings(layout, sortedUuids)
    ) {
      return;
    }
    dispatch(setDialogOpen('saveAsPattern'));
  }, [dispatch, layout, selection.items]);

  return {
    deleteSelection,
    copySelection,
    pasteAfterSelection,
    duplicateSelection,
    saveSelectionAsPattern,
  };
}

export default useMultiSelectionOperations;

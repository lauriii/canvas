import { v4 as uuidv4 } from 'uuid';

import { useAppDispatch, useAppSelector } from '@/app/hooks';
import {
  insertNodes,
  selectLayout,
  selectModel,
} from '@/features/layout/layoutModelSlice';
import {
  areConsecutiveSiblings,
  findComponentByUuid,
  findNodePathByUuid,
  recurseNodes,
  sortUuidsByDocumentOrder,
} from '@/features/layout/layoutUtils';
import { selectSelection } from '@/features/ui/uiSlice';
import useComponentSelection from '@/hooks/useComponentSelection';

import type { AppThunk } from '@/app/store';
import type {
  ComponentModels,
  ComponentNode,
  LayoutModelPiece,
} from '@/features/layout/layoutModelSlice';

interface CopyPasteFunctions {
  copySelectedComponent: (component?: string) => void;
  pasteAfterSelectedComponent: (component?: string) => void;
}

function useCopyPasteComponents(): CopyPasteFunctions {
  const dispatch = useAppDispatch();
  const model = useAppSelector(selectModel);
  const layout = useAppSelector(selectLayout);
  const selection = useAppSelector(selectSelection);
  const { updateSelectionInRedux } = useComponentSelection();

  // Resolves the components an operation targets: an explicitly passed
  // component wins, otherwise the current selection in document order.
  const getTargetComponents = (component?: string): string[] => {
    if (component) {
      return [component];
    }
    return sortUuidsByDocumentOrder(layout, selection.items);
  };

  const copySelectedComponent = (component?: string) => {
    const targetComponents = getTargetComponents(component);
    if (!targetComponents.length) {
      return;
    }
    // Copying a group is only defined for consecutive siblings, matching the
    // constraint communicated in the UI. Recompute against the current layout
    // rather than trusting the stored flag, which can go stale on undo/redo.
    if (
      targetComponents.length > 1 &&
      !areConsecutiveSiblings(layout, targetComponents)
    ) {
      return;
    }

    // The clipboard holds an ordered list of component subtrees, in document
    // order, plus the model data of every copied component and its children.
    const copiedComponents: ComponentNode[] = [];
    const copiedModels: ComponentModels = {};
    for (const uuid of targetComponents) {
      const copiedComponent = findComponentByUuid(layout, uuid);
      if (!copiedComponent) {
        return;
      }
      copiedComponents.push(copiedComponent);
      // Recursively get ALL the model data for not just the selected component but also all of its children.
      copiedModels[uuid] = model[uuid];
      recurseNodes(copiedComponent, (node: ComponentNode) => {
        copiedModels[node.uuid] = model[node.uuid];
      });
    }

    localStorage.setItem(
      'copiedComponent',
      JSON.stringify({
        model: copiedModels,
        layout: copiedComponents,
      } as LayoutModelPiece),
    );
  };

  const pasteAfterSelectedComponent = (component?: string) => {
    const targetComponents = getTargetComponents(component);
    if (!targetComponents.length) {
      return;
    }
    // Paste after the last targeted component in document order.
    const destinationUUID = targetComponents[targetComponents.length - 1];
    const serializedCopiedComponent = localStorage.getItem('copiedComponent');
    let componentFromClipboard: LayoutModelPiece;

    if (!serializedCopiedComponent) {
      return;
    }
    try {
      componentFromClipboard = JSON.parse(serializedCopiedComponent);
    } catch (err) {
      return;
    }

    const to = findNodePathByUuid(layout, destinationUUID);
    if (!to) {
      return;
    }
    to[to.length - 1]++;

    // Assign a UUID to each pasted top-level node so the whole group can
    // become the selection afterwards.
    const assignedUUIDs = componentFromClipboard.layout.map(() => uuidv4());
    dispatch(
      insertNodes({
        to: to,
        layoutModel: componentFromClipboard,
        useUUID: assignedUUIDs,
      }),
    );
    // Select the pasted components using the post-insert layout, so the
    // consecutive-siblings status of the new selection is computed correctly.
    const selectPasted: AppThunk = (_dispatch, getState) => {
      updateSelectionInRedux(assignedUUIDs, selectLayout(getState()));
    };
    dispatch(selectPasted);
  };

  return { pasteAfterSelectedComponent, copySelectedComponent };
}

export default useCopyPasteComponents;

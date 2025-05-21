import { useAppDispatch, useAppSelector } from '@/app/hooks';
import {
  findComponentByUuid,
  findNodePathByUuid,
  recurseNodes,
} from '@/features/layout/layoutUtils';
import type {
  ComponentNode,
  LayoutModelPiece,
} from '@/features/layout/layoutModelSlice';
import {
  insertNodes,
  selectLayout,
  selectModel,
} from '@/features/layout/layoutModelSlice';
import { v4 as uuidv4 } from 'uuid';
import useComponentSelection from '@/hooks/useComponentSelection';
import { useParams } from 'react-router';

interface CopyPasteFunctions {
  copySelectedComponent: (component?: string) => void;
  pasteAfterSelectedComponent: (component?: string) => void;
}
function useCopyPasteComponents(): CopyPasteFunctions {
  const dispatch = useAppDispatch();
  const { componentId: selectedComponent } = useParams();
  const model = useAppSelector(selectModel);
  const layout = useAppSelector(selectLayout);
  const { setSelectedComponent } = useComponentSelection();
  const copySelectedComponent = (component?: string) => {
    const targetComponent = component || selectedComponent;
    if (!targetComponent) {
      return;
    }
    const copiedComponent = findComponentByUuid(layout, targetComponent);
    if (!copiedComponent) {
      return;
    }
    // Recursively get ALL the model data for not just the selected component but also all of its children.
    const copiedModels = { [targetComponent]: model[targetComponent] };
    recurseNodes(copiedComponent, (node: ComponentNode) => {
      copiedModels[node.uuid] = model[node.uuid];
    });

    localStorage.setItem(
      'copiedComponent',
      JSON.stringify({
        model: copiedModels,
        layout: [copiedComponent],
      } as LayoutModelPiece),
    );
  };

  const pasteAfterSelectedComponent = (component?: string) => {
    const targetComponent = component || selectedComponent;
    if (!targetComponent) {
      return;
    }
    const destinationUUID = targetComponent;
    const serializedCopiedComponent = localStorage.getItem('copiedComponent');
    let componentFromClipboard;

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

    const assignedUUID = uuidv4();
    dispatch(
      insertNodes({
        to: to,
        layoutModel: componentFromClipboard,
        useUUID: assignedUUID,
      }),
    );
    setSelectedComponent(assignedUUID);
  };

  return { pasteAfterSelectedComponent, copySelectedComponent };
}

export default useCopyPasteComponents;

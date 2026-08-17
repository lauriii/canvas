import { useParams } from 'react-router';
import { toast } from 'sonner';
import { v4 as uuidv4 } from 'uuid';

import { useAppDispatch, useAppSelector } from '@/app/hooks';
import {
  insertNodes,
  NodeType,
  selectLayout,
  selectModel,
} from '@/features/layout/layoutModelSlice';
import {
  findComponentByUuid,
  findNodePathByUuid,
  findParent,
  recurseNodes,
} from '@/features/layout/layoutUtils';
import { componentIdFromNodeType } from '@/features/layout/slot-utils';
import useComponentSelection from '@/hooks/useComponentSelection';
import { useSlotRejection } from '@/hooks/useSlotRestrictions';

import type {
  ComponentNode,
  LayoutModelPiece,
} from '@/features/layout/layoutModelSlice';

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
  const slotRejection = useSlotRejection();
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

    // Pasting lands the clipboard next to the destination, so it is the
    // destination's own parent slot that has to accept it. Without this check
    // one keystroke can put a component somewhere publishing then refuses.
    // @see \Drupal\canvas\SlotRestrictions
    const parent = findParent(layout, destinationUUID);
    if (parent?.nodeType === NodeType.Slot) {
      const rejection = slotRejection(
        parent,
        ((componentFromClipboard.layout ?? []) as ComponentNode[]).map((node) =>
          componentIdFromNodeType(node.type),
        ),
      );
      if (rejection) {
        toast.error(rejection.reason);
        return;
      }
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

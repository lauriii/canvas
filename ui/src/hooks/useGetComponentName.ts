import { useGetComponentsQuery } from '@/services/componentAndLayout';
import { useCallback } from 'react';
import type {
  LayoutChildNode,
  LayoutNode,
} from '@/features/layout/layoutModelSlice';
import type { XBComponent } from '@/types/Component';
import { componentHasFieldData } from '@/types/Component';

const useGetComponentName = (
  node: LayoutChildNode | null,
  parentNode?: LayoutNode | null,
) => {
  const { data: components } = useGetComponentsQuery();

  const findPresentationSlotName = (
    slotName: string | undefined,
    parentComponent: XBComponent,
  ) => {
    if (
      slotName &&
      componentHasFieldData(parentComponent) &&
      parentComponent.metadata.slots
    ) {
      return parentComponent.metadata.slots[slotName].title;
    }
    return 'Slot';
  };

  const getName = useCallback(() => {
    if (node === null) {
      return '';
    }
    let name: string = node.nodeType;
    if (components) {
      if (node.nodeType === 'component') {
        if (node.type) {
          const [nodeType] = node.type.split('@');
          name = components[nodeType]?.name || 'Component';
        } else {
          name = 'Component';
        }
      } else {
        name = node.name || 'Slot';
        if (parentNode && 'type' in parentNode) {
          const [parentType] = (parentNode.type ?? '').split('@');
          if (parentNode.type) {
            const parentComponent = components?.[parentType];
            name = findPresentationSlotName(node.name, parentComponent);
          }
        }
      }
    }
    return name;
  }, [node, parentNode, components]);

  return getName();
};

export default useGetComponentName;

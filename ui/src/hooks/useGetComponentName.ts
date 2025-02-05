import { useGetComponentsQuery } from '@/services/components';
import { useCallback } from 'react';
import type {
  LayoutChildNode,
  LayoutNode,
} from '@/features/layout/layoutModelSlice';
import type { Component } from '@/types/Component';
import { componentHasFieldData } from '@/types/Component';

const useGetComponentName = (
  node: LayoutChildNode | null,
  parentNode?: LayoutNode | null,
) => {
  const { data: components } = useGetComponentsQuery();

  const findPresentationSlotName = (
    slotName: string | undefined,
    parentComponent: Component,
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
          name = components[node.type]?.name || 'Component';
        } else {
          name = 'Component';
        }
      } else {
        name = node.name || 'Slot';
        if (parentNode && 'type' in parentNode) {
          if (parentNode.type) {
            const parentComponent = components?.[parentNode.type];
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

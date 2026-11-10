import { useCallback } from 'react';

import { useAppSelector } from '@/app/hooks';
import { getDisplayNameForNode } from '@/features/layout/layoutUtils';
import { componentAndLayoutApi } from '@/services/componentAndLayout';

import type {
  ComponentNode,
  LayoutChildNode,
  LayoutNode,
  SlotNode,
} from '@/features/layout/layoutModelSlice';
import type { ComponentsList } from '@/types/Component';

const selectComponents = componentAndLayoutApi.endpoints.getComponents.select();

/**
 * Returns the display name of a component or slot node.
 *
 * This runs once or more per component instance (Layers rows, preview
 * overlays, context menus), and each caller's selector runs on every store
 * update. useGetComponentsQuery() would build a chain of query state selectors
 * per caller. This subscribes to the query without reading its state, and
 * reads the data through one memoized selector that all callers share.
 */
const useGetComponentName = (
  node: LayoutChildNode | null,
  parentNode?: LayoutNode | null,
) => {
  componentAndLayoutApi.endpoints.getComponents.useQuerySubscription();
  const components = useAppSelector((state) => selectComponents(state).data);

  // Helper to cast parentNode to ComponentNode if needed
  const getParentComponentNode = (
    parent: LayoutNode | null | undefined,
  ): ComponentNode | null => {
    if (parent && 'type' in parent) {
      return parent as ComponentNode;
    }
    return null;
  };

  // Helper to cast node to SlotNode if needed
  const getSlotNode = (n: LayoutChildNode | null): SlotNode | null => {
    if (n && n.nodeType === 'slot') {
      return n as SlotNode;
    }
    return null;
  };

  // Helper to cast node to ComponentNode if needed
  const getComponentNode = (
    n: LayoutChildNode | null,
  ): ComponentNode | null => {
    if (n && n.nodeType === 'component') {
      return n as ComponentNode;
    }
    return null;
  };

  const getName = useCallback(() => {
    if (!node || !components) return '';
    if (node.nodeType === 'component') {
      return getDisplayNameForNode(
        getComponentNode(node),
        null,
        components as ComponentsList,
      );
    } else if (node.nodeType === 'slot') {
      return getDisplayNameForNode(
        getSlotNode(node),
        getParentComponentNode(parentNode),
        components as ComponentsList,
      );
    }
    return '';
  }, [node, parentNode, components]);

  return getName();
};

export default useGetComponentName;

import type {
  ComponentModels,
  ComponentNode,
  LayoutChildNode,
  LayoutNode,
  RegionNode,
  SlotNode,
} from './layoutModelSlice';
import { NodeType } from './layoutModelSlice';
import { v4 as uuidv4 } from 'uuid';
import { setXbDrupalSetting } from '@/features/drupal/drupalUtil';

type NodeFunction = (
  node: ComponentNode,
  index: number,
  parent: LayoutChildNode,
) => void;

// The children of Slots and Regions are in components[] but Components' children are always in slots[].
function getChildKeyByType(type: NodeType): 'slots' | 'components' {
  return type === 'component' ? 'slots' : 'components';
}

function getNodeIdentifier(node: LayoutNode): string {
  switch (node.nodeType) {
    case NodeType.Slot:
      return node.id;
    case NodeType.Component:
      return node.uuid;
    case NodeType.Region:
      return node.id;
    default:
      throw new Error('Unknown node type');
  }
}

/**
 * Recursively run one or multiple functions against a node and all its descendants.
 * @param node - The top LayoutChildNode (a Component or a Slot) from which the recursion will start.
 * @param functionOrFunctions - A function or an array of functions to run on a LayoutChildNode and all of its descendant nodes.
 * Each function is passed 3 parameters: the LayoutChildNode, its index, and its direct parent.
 */
export function recurseNodes(
  node: LayoutChildNode,
  functionOrFunctions: NodeFunction | NodeFunction[] = [],
): void {
  const childKey = getChildKeyByType(node.nodeType);
  let functionsToRun: NodeFunction[] = Array.isArray(functionOrFunctions)
    ? functionOrFunctions
    : [functionOrFunctions];
  let children: LayoutChildNode[];

  if (childKey === 'slots' && 'slots' in node) {
    children = node.slots;
  } else if (childKey === 'components' && 'components' in node) {
    children = node.components;
  } else {
    children = [];
  }

  // Loop backwards in case the array is modified by the passed function/functions
  for (let index = children.length - 1; index >= 0; index--) {
    const child = children[index];

    if (child.nodeType === 'component') {
      functionsToRun.forEach((func) => {
        if (typeof func === 'function') {
          func(child, index, node);
        }
      });
    }

    recurseNodes(child, functionOrFunctions);
  }
}

/**
 * Find a Component by its UUID.
 * @param roots - The starting node to search from.
 * @param uuid - The id of the node to find.
 * @returns The found component or null if not found.
 */
export function findComponentByUuid(
  roots: Array<RegionNode>,
  uuid: string,
): ComponentNode | null {
  const recurseComponents = (
    components: ComponentNode[],
  ): ComponentNode | null => {
    for (const component of components) {
      if (component.uuid === uuid) {
        return component;
      }
      const foundInSlots = recurseSlots(component.slots);
      if (foundInSlots) {
        return foundInSlots;
      }
    }
    return null;
  };

  const recurseSlots = (slots: SlotNode[]): ComponentNode | null => {
    for (const slot of slots) {
      const foundInComponents = recurseComponents(slot.components);
      if (foundInComponents) {
        return foundInComponents;
      }
    }
    return null;
  };

  for (const root of roots) {
    const found = recurseComponents(root.components);
    if (found) {
      return found;
    }
  }
  return null;
}

/**
 * Find the path to a node by its UUID.
 * @param nodes - The nodes to search through.
 * @param id - The UUID of the node to find.
 * @param path - The current path (used internally for recursion).
 * @returns The path to the node as an array of indices, or null if not found.
 */
export function findNodePathByUuid(
  nodes: Array<LayoutNode>,
  id: string | undefined,
  path: number[] = [],
): number[] | null {
  if (!id) {
    console.error('No id provided to findNodePathByUuid.');
    return null;
  }

  for (let i = 0; i < nodes.length; i++) {
    const node = nodes[i];
    const nodeId = getNodeIdentifier(node);

    if (nodeId === id) {
      return path.concat(i);
    }
    const childKey = getChildKeyByType(node.nodeType);
    let children: LayoutChildNode[] = [];
    if (childKey === 'slots' && 'slots' in node) {
      children = node.slots;
    } else if (childKey === 'components' && 'components' in node) {
      children = node.components;
    }
    const result = findNodePathByUuid(children, id, path.concat(i));
    if (result !== null) {
      return result;
    }
  }

  // If the node is not found in this subtree, return null
  return null;
}

/**
 * Remove a node from a Region by its UUID.
 * @param nodes - The root RegionNodes.
 * @param uuid - The UUID of the node to remove.
 * @returns A deep clone of the RegionNodes with the node matching the uuid removed.
 */
export function removeComponentByUuid(
  nodes: Array<RegionNode>,
  uuid: string,
): Array<RegionNode> {
  const newState = JSON.parse(JSON.stringify(nodes));

  const path = findNodePathByUuid(newState, uuid);

  if (path) {
    const rootIndex = path.shift();
    if (rootIndex === undefined) {
      throw new Error(`Component with ID ${uuid} not found`);
    }
    let parent: LayoutNode = newState[rootIndex];
    let currentNode: LayoutNode = parent;
    let childIndex: number | null = null;

    path.forEach((idx, i) => {
      const childKey = i % 2 === 0 ? 'components' : 'slots';

      parent = currentNode;
      childIndex = idx;
      if (childKey === 'components' && 'components' in currentNode) {
        currentNode = currentNode.components[idx];
      } else if (childKey === 'slots' && 'slots' in currentNode) {
        currentNode = currentNode.slots[idx];
      } else {
        throw new Error('Invalid tree structure encountered.');
      }
    });

    // Remove the node from its parent's components list
    if (parent && childIndex !== null && 'components' in parent) {
      parent.components.splice(childIndex, 1);
    }
  }

  return newState;
}

/**
 * Insert a node at a specific path.
 * @param layoutNode - The starting node to insert into.
 * @param path - The path where the new node should be inserted.
 * @param newNode - The new node to insert.
 * @returns A deep clone of the node with the newNode inserted at path.
 */
export function insertNodeAtPath<T extends LayoutNode>(
  layoutNode: T,
  path: number[],
  newNode: LayoutChildNode,
): T {
  const newState = JSON.parse(JSON.stringify(layoutNode));

  if (path.length === 0) {
    throw new Error(
      'Path must have at least one element to define where to insert the node.',
    );
  }

  const childKey = getChildKeyByType(newState.nodeType);

  let children: LayoutChildNode[] = [];

  if (childKey === 'slots' && 'slots' in newState) {
    children = newState.slots;
  } else if (childKey === 'components' && 'components' in newState) {
    children = newState.components;
  }

  // Base case: if the path has only one element, insert the new node at the specified index
  if (path.length === 1) {
    children.splice(path[0], 0, newNode);
    return newState;
  }

  // Recursive case: navigate down the path
  const [currentIndex, ...restOfPath] = path;

  if (!children[currentIndex]) {
    throw new Error('Path must resolve to a node in the tree.');
  }

  // Recursively insert the node at the remaining path and update the child node
  children[currentIndex] = insertNodeAtPath(
    children[currentIndex],
    restOfPath,
    newNode,
  );

  return newState;
}

/**
 * Move a node to a new path.
 * @param rootNodes - The root node of the layout.
 * @param uuid - The UUID of the component to move.
 * @param path - The path to move the node to.
 * @returns A deep clone of the `rootNode` with the node matching the `uuid` moved to the `path`.
 */
export function moveNodeToPath(
  rootNodes: Array<RegionNode>,
  uuid: string,
  path: number[],
): Array<RegionNode> {
  const child = findComponentByUuid(rootNodes, uuid);
  if (!child) {
    throw new Error(`Node with UUID ${uuid} not found.`);
  }
  // Make a clone of the node that is being moved.
  const clone = JSON.parse(JSON.stringify(child));
  // flag the original node for deletion
  child.uuid = child.uuid + '_remove';

  // Insert the clone at toPath
  const rootIndex = path.shift();
  if (rootIndex === undefined) {
    throw new Error(
      'Path should be at least two items long, starting from the root region',
    );
  }
  const root = rootNodes[rootIndex];
  const newState = rootNodes;
  newState[rootIndex] = insertNodeAtPath(root, path, clone);

  // Remove the original node by finding it by uuid (which is now `${child.uuid}_remove`)
  return removeComponentByUuid(newState, child.uuid);
}

/**
 * Checks if a node is a child of another node.
 * @param layout - The root node.
 * @param uuid - The UUID of the node to check.
 * @returns {boolean | null} - Returns if node is a child or not and null if the node is not found.
 */
export function isChildNode(
  layout: Array<LayoutNode>,
  uuid: string,
): boolean | null {
  const path = findNodePathByUuid(layout, uuid);
  if (path !== null) {
    return path && path.length > 2;
  } else {
    return null;
  }
}

/**
 * Get the depth of the node in the layout tree from the root.
 * @param layoutNodes - The root node.
 * @param uuid - The UUID of the node to check.
 * @returns Depth of a node as an integer.
 */
export function getNodeDepth(
  layoutNodes: Array<LayoutNode>,
  uuid: string | undefined,
) {
  const path = findNodePathByUuid(layoutNodes, uuid);
  if (path) {
    return path.length - 1;
  }
  return 0;
}

/**
 * Replace UUIDs in a layout node and its corresponding model.
 * @param component - The component to update. Can have slots, child components etc
 * @param model - The corresponding model object to update. Should contain models for component and all children.
 * @param newUUID - Optionally specify the UUID of the new Component.
 * @returns An updated model and an updated state.
 */
export function replaceUUIDsAndUpdateModel(
  component: ComponentNode,
  model: ComponentModels,
  newUUID?: string,
): {
  updatedNode: ComponentNode;
  updatedModel: ComponentModels;
} {
  const oldToNewUUIDMap: Record<string, string> = {};
  const updatedModel: ComponentModels = {};

  const replaceUUIDsInComponents = (
    components: ComponentNode[],
    newUUID?: string,
  ): ComponentNode[] => {
    return components.map((component, i) => {
      const newUuid = i === 0 && newUUID ? newUUID : uuidv4();
      const newComponent: ComponentNode = { ...component, uuid: newUuid };

      oldToNewUUIDMap[component.uuid] = newComponent.uuid;

      newComponent.slots = replaceUUIDsInSlots(
        newComponent.slots,
        newComponent.uuid,
      );

      return newComponent;
    });
  };

  const replaceUUIDsInSlots = (
    slots: SlotNode[],
    parentUuid?: string,
  ): SlotNode[] => {
    return slots.map((slot) => {
      const newSlot: SlotNode = {
        ...slot,
        id: `${parentUuid}/${slot.name}`,
      };

      newSlot.components = replaceUUIDsInComponents(newSlot.components);

      return newSlot;
    });
  };

  const updatedNode = replaceUUIDsInComponents([component], newUUID)[0];

  // Update the model keys
  for (const oldUUID in model) {
    const newUUID = oldToNewUUIDMap[oldUUID];
    if (newUUID) {
      updatedModel[newUUID] = JSON.parse(JSON.stringify(model[oldUUID]));
    }
  }

  return { updatedNode, updatedModel };
}

/**
 * Takes an array of RegionNodes and the UUID of a component and returns the region node that contains a ComponentNode
 * that matches the UUID.
 * @param layout - an array of RegionNode
 * @param uuid - a uuid of a component somewhere in the layout
 */
export function findParentRegion(
  layout: RegionNode[],
  uuid: string,
): RegionNode | undefined {
  function findInSlots(slots: SlotNode[], uuid: string): boolean {
    for (const slot of slots) {
      for (const component of slot.components) {
        if (component.uuid === uuid || findInSlots(component.slots, uuid)) {
          return true;
        }
      }
    }
    return false;
  }

  for (const region of layout) {
    for (const component of region.components) {
      if (component.uuid === uuid || findInSlots(component.slots, uuid)) {
        return region;
      }
    }
  }

  return undefined;
}

// Add the utils provided here to drupalSettings, so extensions have access to
// them.
const layoutUtils = {
  getChildKeyByType,
  getNodeIdentifier,
  recurseNodes,
  findComponentByUuid,
  removeComponentByUuid,
  findNodePathByUuid,
  insertNodeAtPath,
  moveNodeToPath,
  isChildNode,
  getNodeDepth,
  replaceUUIDsAndUpdateModel,
  findParentRegion,
};
setXbDrupalSetting('layoutUtils', layoutUtils);

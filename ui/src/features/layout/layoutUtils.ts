import { v4 as uuidv4 } from 'uuid';

import { hasSlotDefinitions } from '@/types/Component';
import { setCanvasDrupalSetting } from '@/utils/drupal-globals';
import { isConsecutive } from '@/utils/function-utils';

import { NodeType } from './layoutModelSlice';

import type { ComponentsList } from '@/types/Component';
import type {
  ComponentModels,
  ComponentNode,
  LayoutChildNode,
  LayoutNode,
  RegionNode,
  SlotNode,
} from './layoutModelSlice';

type NodeFunction = (
  node: ComponentNode,
  index: number,
  parent: LayoutNode,
) => void;

// Retrieves the children of a given node based on its type.
// The children array is extracted differently depending on whether the node is a Component, Region, or Slot.
function getChildrenFromNode(node: LayoutNode): LayoutChildNode[] {
  switch (node.nodeType) {
    case NodeType.Region:
    case NodeType.Slot:
      return node.components;

    case NodeType.Component:
      return node.slots;

    default:
      throw new Error('Unknown node type');
  }
}

// Returns the unique identifier (string) for a given node.
// Each node type has a specific identifier field: id for Slot and Region, uuid for Component.
function getNodeIdentifier(node: LayoutNode): string {
  switch (node.nodeType) {
    case NodeType.Slot:
    case NodeType.Region:
      return node.id;

    case NodeType.Component:
      return node.uuid;

    default:
      throw new Error('Unknown node type');
  }
}

/**
 * Recursively run one or multiple functions against a node and all its descendants.
 * @param node - The top LayoutNode (a Component or a Slot or a Region) from which the recursion will start.
 * @param functionOrFunctions - A function or an array of functions to run on a LayoutNode and all of its descendant nodes.
 * Each function is passed 3 parameters: the LayoutNode, its index, and its direct parent.
 */
export function recurseNodes(
  node: LayoutNode,
  functionOrFunctions: NodeFunction | NodeFunction[] = [],
): void {
  const functionsToRun: NodeFunction[] = Array.isArray(functionOrFunctions)
    ? functionOrFunctions
    : [functionOrFunctions];

  const children: LayoutChildNode[] = getChildrenFromNode(node);

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
 * Find a Component or Slot by a given identifier.
 * @param roots - The starting node to search from.
 * @param identifier - The id of the node to find.
 * @param type - The type to search for ('component' or 'slot').
 * @returns The found node or null if not found.
 */
function findByIdentifier(
  roots: Array<RegionNode>,
  identifier: string,
  type: 'component' | 'slot',
): ComponentNode | SlotNode | null {
  const recurseComponents = (
    components: ComponentNode[],
  ): ComponentNode | SlotNode | null => {
    for (const component of components) {
      if (type === 'component' && component.uuid === identifier) {
        return component;
      }

      const foundInSlots = recurseSlots(component.slots);
      if (foundInSlots) {
        return foundInSlots;
      }
    }
    return null;
  };

  const recurseSlots = (slots: SlotNode[]): ComponentNode | SlotNode | null => {
    for (const slot of slots) {
      if (type === 'slot' && slot.id === identifier) {
        return slot;
      }
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
 * Finds the parent information and index of a component
 * @param roots - The root region nodes to search in
 * @param uuid - UUID of the component to find the parent for
 * @returns Object with parentId, parentType, and childIndex, or null if not found
 */
export function findParentInfo(
  roots: Array<RegionNode>,
  uuid: string,
): { parentId: string; parentType: string; childIndex: number } | null {
  // Check if component is directly in a region
  for (const region of roots) {
    for (let i = 0; i < region.components.length; i++) {
      if (region.components[i].uuid === uuid) {
        return {
          parentId: region.id,
          parentType: 'region',
          childIndex: i,
        };
      }
    }
  }

  // Track the parent as we recurse through the tree
  let result: {
    parentId: string;
    parentType: string;
    childIndex: number;
  } | null = null;

  // Use recurseNodes to search for the component in the tree
  const findParent = (node: ComponentNode) => {
    // For each slot in this component
    for (const slot of node.slots) {
      // Check if the component is a direct child of this slot
      for (let i = 0; i < slot.components.length; i++) {
        if (slot.components[i].uuid === uuid) {
          result = {
            parentId: slot.id,
            parentType: 'slot',
            childIndex: i,
          };
          return;
        }
      }
    }
  };

  // Apply the function to all nodes in the tree
  for (const region of roots) {
    recurseNodes(region, findParent);
    if (result) break;
  }

  return result;
}

/**
 * Checks if all the components with the given UUIDs are consecutive siblings
 * (share the same parent slot or region AND are consecutive in order)
 * @param roots - The root region nodes to search in
 * @param uuids - Array of component UUIDs to check
 * @returns True if all components are consecutive siblings, false otherwise
 */
export function areConsecutiveSiblings(
  roots: Array<RegionNode>,
  uuids: string[],
): boolean {
  // If there are no UUIDs or only one, they're considered siblings by default
  if (uuids.length <= 1) {
    return true;
  }

  // Map each UUID to its parent info (which includes child index)
  const parentInfos = uuids
    .map((uuid) => findParentInfo(roots, uuid))
    .filter((info) => info !== null) as Array<{
    parentId: string;
    parentType: string;
    childIndex: number;
  }>;

  // If any component wasn't found, return false
  if (parentInfos.length !== uuids.length) {
    return false;
  }

  // First check if all components have the same parent
  const firstParent = parentInfos[0];

  const allSameParent = parentInfos.every(
    (info) =>
      info.parentId === firstParent.parentId &&
      info.parentType === firstParent.parentType,
  );

  if (!allSameParent) {
    return false;
  }

  // Sort the entries by child index
  const sortedIndexes = parentInfos
    .map((info) => info.childIndex)
    .sort((a, b) => a - b);

  return isConsecutive(sortedIndexes);
}

/**
 * Sorts component UUIDs into document order: the order the components appear
 * in the layout tree when read region by region, depth first. Batch
 * operations (copy, duplicate, paste) rely on this so their result reflects
 * what the user sees on the page rather than the order they clicked.
 * @param roots - The root region nodes to search in
 * @param uuids - Component UUIDs to sort
 * @returns A new array with the found UUIDs in document order. UUIDs not
 *   present in the layout are omitted.
 */
export function sortUuidsByDocumentOrder(
  roots: Array<RegionNode>,
  uuids: string[],
): string[] {
  return uuids
    .map((uuid) => ({ uuid, path: findNodePathByUuid(roots, uuid) }))
    .filter(
      (entry): entry is { uuid: string; path: number[] } => entry.path !== null,
    )
    .sort((a, b) => {
      // Lexicographic comparison of tree paths equals document order.
      const length = Math.min(a.path.length, b.path.length);
      for (let i = 0; i < length; i++) {
        if (a.path[i] !== b.path[i]) {
          return a.path[i] - b.path[i];
        }
      }
      return a.path.length - b.path.length;
    })
    .map((entry) => entry.uuid);
}

/**
 * Filters out any components that are parents or children of components in the selection
 * @param layout - The entire layout tree
 * @param currentSelection - Current selection array
 * @param newComponentUuid - UUID of the component (potentially) being added to selection
 * @returns An array of component UUIDs with no parent-child relationships
 */
export function filterParentChildRelationships(
  layout: RegionNode[],
  currentSelection: string[],
  newComponentUuid: string,
): string[] {
  // If we're adding to an empty selection, no filtering needed
  if (currentSelection.length === 0) {
    return [newComponentUuid];
  }

  // First check if the new component is a parent of any currently selected components
  const newComponent = findComponentByUuid(layout, newComponentUuid);
  if (!newComponent) {
    return currentSelection; // Component not found, return current selection
  }

  // Check if the new component is a parent of any selected components
  // If so, we need to remove those child components from the selection
  const childrenToRemove = currentSelection.filter((selectedUuid) =>
    isParentOf(newComponent, selectedUuid),
  );

  // Check if the new component is a child of any selected components
  // If so, we need to remove those parent components from the selection
  const parentsToRemove = currentSelection.filter((selectedUuid) => {
    const possibleParent = findComponentByUuid(layout, selectedUuid);
    return possibleParent && isParentOf(possibleParent, newComponentUuid);
  });

  // Create a new selection with:
  // 1. All items from current selection
  // 2. Minus any children of the new component
  // 3. Minus any parents of the new component
  // 4. Plus the new component
  const itemsToRemove = new Set([...childrenToRemove, ...parentsToRemove]);
  const filteredSelection = currentSelection.filter(
    (uuid) => !itemsToRemove.has(uuid),
  );

  return [...filteredSelection, newComponentUuid];
}

/**
 * Find a Component by its UUID.
 * @param roots - The starting node to search from.
 * @param uuid - The uuid of the component to find.
 * @returns The found component or null if not found.
 */
export function findComponentByUuid(
  roots: Array<RegionNode>,
  uuid: string,
): ComponentNode | null {
  return findByIdentifier(roots, uuid, 'component') as ComponentNode | null;
}

/**
 * Find a Slot by its ID.
 * @param roots - The starting node to search from.
 * @param id - The id of the slot to find.
 * @returns The found slot or null if not found.
 */
export function findSlotById(
  roots: Array<RegionNode>,
  id: string,
): SlotNode | null {
  return findByIdentifier(roots, id, 'slot') as SlotNode | null;
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
    const children: LayoutChildNode[] = getChildrenFromNode(node);
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

  const children: LayoutChildNode[] = getChildrenFromNode(newState);

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
  return moveNodesToPath(rootNodes, [uuid], path);
}

/**
 * Move an ordered group of nodes to a new path as one mutation.
 *
 * The nodes are inserted contiguously at `path`, in the order given, and then
 * removed from their original positions. Inserting before removing means the
 * path can be interpreted against the current layout, and flagging the
 * originals by UUID makes their removal independent of index shifts. The
 * group does not need to be consecutive or share a parent.
 *
 * @param rootNodes - The root nodes of the layout.
 * @param uuids - UUIDs of the components to move, in the desired result order.
 * @param path - The path the first moved node should end up at.
 * @returns A deep clone of `rootNodes` with the nodes moved to the `path`.
 */
export function moveNodesToPath(
  rootNodes: Array<RegionNode>,
  uuids: string[],
  path: number[],
): Array<RegionNode> {
  // Validate the whole group before mutating anything, so a missing UUID
  // cannot leave the layout half-moved.
  const children: ComponentNode[] = uuids.map((uuid) => {
    const child = findComponentByUuid(rootNodes, uuid);
    if (!child) {
      throw new Error(`Node with UUID ${uuid} not found.`);
    }
    return child;
  });

  // Make clones of the nodes being moved and flag the originals for deletion.
  const clones: ComponentNode[] = children.map((child) => {
    const clone = JSON.parse(JSON.stringify(child));
    child.uuid = child.uuid + '_remove';
    return clone;
  });

  const rootIndex = path.shift();
  if (rootIndex === undefined) {
    throw new Error(
      'Path should be at least two items long, starting from the root region',
    );
  }
  // Insert the clones in reverse order at the same path so they keep the
  // given order.
  let root = rootNodes[rootIndex];
  for (let i = clones.length - 1; i >= 0; i--) {
    root = insertNodeAtPath(root, path, clones[i]);
  }
  let newState = rootNodes;
  newState[rootIndex] = root;

  // Remove the original nodes by their flagged UUIDs.
  for (const uuid of uuids) {
    newState = removeComponentByUuid(newState, uuid + '_remove');
  }
  return newState;
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

/**
 * Checks if a component exists in a layout.
 * @param layout - an array of RegionNode
 * @param componentId - id of the component entity
 */
export function componentExistsInLayout(
  layout: RegionNode[],
  componentId: string,
): boolean {
  let exists = false;
  const checkComponent = (node: ComponentNode) => {
    const [type] = node.type.split('@');
    if (type === componentId) {
      exists = true;
    }
  };

  for (const region of layout) {
    recurseNodes(region, checkComponent);
    if (exists) {
      break;
    }
  }
  return exists;
}

/**
 * Checks if a component is a parent of another component by recursively traversing the layout tree
 * @param possibleParent - The component to check if it's a parent
 * @param childUuid - UUID of the potential child component
 * @returns true if possibleParent is a parent (or ancestor) of the component with childUuid
 */
export function isParentOf(
  possibleParent: ComponentNode,
  childUuid: string,
): boolean {
  // Check direct child components in each slot
  for (const slot of possibleParent.slots) {
    for (const component of slot.components) {
      // If this component is the one we're looking for, we found a match
      if (component.uuid === childUuid) {
        return true;
      }

      // Recursively check if any of this component's children match
      if (isParentOf(component, childUuid)) {
        return true;
      }
    }
  }

  // No matches found
  return false;
}

/**
 * Get the immediate parent node of a component by UUID.
 * @param regions - The root region nodes to search in
 * @param uuid - UUID of the component to find the parent for
 * @returns The parent node (RegionNode | SlotNode | ComponentNode) or null if not found
 */
export function findParent(
  regions: Array<RegionNode>,
  uuid: string,
): RegionNode | SlotNode | ComponentNode | null {
  // Check if component is directly in a region
  for (const region of regions) {
    for (const component of region.components) {
      if (component.uuid === uuid) {
        return region;
      }
    }
  }
  // Check if component is in a slot
  let foundParent: SlotNode | null = null;
  const checkSlot = (node: ComponentNode) => {
    for (const slot of node.slots) {
      for (const component of slot.components) {
        if (component.uuid === uuid) {
          foundParent = slot;
          return;
        }
      }
    }
  };
  for (const region of regions) {
    recurseNodes(region, checkSlot);
    if (foundParent) return foundParent;
  }
  return null;
}

/**
 * Find the siblings of a component by UUID (excluding itself).
 * @param nodes - The nodes to search in
 * @param uuid - UUID of the component to find siblings for
 * @returns Array of sibling ComponentNodes (with slots, excluding itself)
 */
export function findSiblings(
  nodes: Array<RegionNode>,
  uuid: string,
): ComponentNode[] {
  const parent = findParent(nodes, uuid);
  if (!parent) return [];
  let children: ComponentNode[] = [];
  if ('components' in parent) {
    children = parent.components;
  }
  // Only return sibling, and not itself
  return children.filter((c) => c.uuid !== uuid);
}

/**
 * Find all parent node IDs leading to a node with the given UUID.
 * @param nodes - The nodes to search through.
 * @param targetUuid - The UUID of the node to find.
 * @returns An array of IDs representing the hierarchy path to the node, or null if not found.
 */
export function findNodeParents(
  nodes: Array<RegionNode>,
  targetUuid: string,
): string[] | null {
  if (!targetUuid) {
    console.error('No UUID provided to findNodeParents.');
    return null;
  }

  let result: string[] | null = null;

  // Helper to traverse the tree and find the node
  const findPath = (
    currentNode: LayoutNode,
    currentPath: string[] = [],
  ): string[] | null => {
    const nodeId = getNodeIdentifier(currentNode);
    const newPath = [...currentPath, nodeId];

    // If this is the node we're looking for, return the path
    if (
      currentNode.nodeType === NodeType.Component &&
      currentNode.uuid === targetUuid
    ) {
      return newPath;
    }

    // Determine child nodes based on the node type
    const children: LayoutChildNode[] = getChildrenFromNode(currentNode);

    // Check children
    for (const child of children) {
      const childPath = findPath(child, newPath);
      if (childPath) {
        return childPath; // Found the node in a child, return the path
      }
    }

    return null; // Not found in this branch
  };

  // Search through all regions
  for (const region of nodes) {
    result = findPath(region);
    if (result) {
      break; // Found the node, no need to check other regions
    }
  }

  return result;
}

/**
 * Shared utility to get the display name for a component or slot node.
 * @param node - The node (component or slot)
 * @param parentComponentNode - The parent component node (for slots)
 * @param componentsData - The components data from useGetComponentsQuery
 * @returns The display name for the node
 */
export function getDisplayNameForNode(
  node: ComponentNode | SlotNode | null,
  parentComponentNode: ComponentNode | null | undefined,
  componentsData: ComponentsList | undefined,
): string {
  if (!node) return '';
  if ('type' in node) {
    // ComponentNode
    const [nodeType] = node.type.split('@');
    return componentsData?.[nodeType]?.name || 'Component';
  } else {
    // SlotNode
    if (parentComponentNode && parentComponentNode.type && node.name) {
      const [parentType] = parentComponentNode.type.split('@');
      const parentComponent = componentsData?.[parentType];
      if (
        hasSlotDefinitions(parentComponent) &&
        parentComponent.metadata.slots[node.name]
      ) {
        return parentComponent.metadata.slots[node.name].title || node.name;
      }
    }
    return node.name || 'Slot';
  }
}

// Add the utils provided here to drupalSettings, so extensions have access to
// them.
const layoutUtils = {
  getChildrenFromNode,
  getNodeIdentifier,
  recurseNodes,
  findComponentByUuid,
  findSlotById,
  removeComponentByUuid,
  findNodePathByUuid,
  findNodeParents,
  insertNodeAtPath,
  moveNodeToPath,
  moveNodesToPath,
  isChildNode,
  getNodeDepth,
  replaceUUIDsAndUpdateModel,
  findParentRegion,
  componentExistsInLayout,
  isParentOf,
  findParentInfo,
  areConsecutiveSiblings,
  sortUuidsByDocumentOrder,
  filterParentChildRelationships,
  findParent,
  findSiblings,
};
setCanvasDrupalSetting('layoutUtils', layoutUtils);

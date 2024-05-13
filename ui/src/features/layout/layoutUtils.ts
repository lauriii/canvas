import _, { lastIndexOf } from "lodash";
import type { LayoutNode } from "./layoutSlice";

//   recurseNodes,
//   findNodeByUuid,
//   findNodePathByUuid,
//   insertNodeAtPath,
//   removeNodeByUuid,
//   moveNodeToPath,

/**
 * Recursively run one or multiple functions against a node and all its descendants.
 * @param node - A layout or layout node.
 * @param functionOrFunctions - A function or an array of functions to run on a node and all of its child nodes.
 * Each function is passed 3 parameters: the node, its index, and its direct parent.
 */
export function recurseNodes(node: LayoutNode | LayoutNode[], functionOrFunctions: Function | Function[] = []): void {
  let functionsToRun: Function[] = _.castArray(functionOrFunctions);

  let children: LayoutNode[] = Array.isArray(node) ? node : node.children || [];

  // Loop backwards in case the array is modified by the passed function/functions
  for (let index = children.length - 1; index >= 0; index--) {
    const child = children[index];

    functionsToRun.forEach(func => {
      if (typeof func === "function") {
        func(child, index, node);
      }
    });

    if (child.children && child.children.length) {
      recurseNodes(child, functionOrFunctions);
    }
  }
}

/**
 * Find a node by its UUID.
 * @param node - The starting node to search from.
 * @param uuid - The UUID of the node to find.
 * @returns The found node or null if not found.
 */
export function findNodeByUuid(node: LayoutNode, uuid: string): LayoutNode | null {
  if (node.uuid === uuid) {
    return node;
  }
  if (node.children) {
    for (const child of node.children) {
      const result = findNodeByUuid(child, uuid);
      if (result) {
        return result;
      }
    }
  }
  return null;
}

/**
 * Find the path to a node by its UUID.
 * @param node - The starting node to search from.
 * @param uuid - The UUID of the node to find.
 * @param path - The current path (used internally for recursion).
 * @returns The path to the node as an array of indices, or null if not found.
 */
export function findNodePathByUuid(node: LayoutNode, uuid: string | undefined, path: number[] = []): number[] | null {
  if (!uuid) {
    console.error("No uuid provided to findNodePathByUuid.");
    return null;
  }
  if (node.uuid === uuid) {
    return path;
  }

  if (node.children) {
    for (let i = 0; i < node.children.length; i++) {
      const child = node.children[i];
      // Recursively search in the child node, appending the current index to the path
      const result = findNodePathByUuid(child, uuid, path.concat(i));
      // If the result is not null, the node has been found in the subtree
      if (result !== null) {
        return result;
      }
    }
  }

  // If the node is not found in this subtree, return null
  return null;
}

/**
 * Remove a node by its UUID.
 * @param node - The starting node to search from.
 * @param uuid - The UUID of the node to remove.
 * @returns A deep clone of the node with the node matching the uuid removed.
 */
export function removeNodeByUuid(node: LayoutNode, uuid: string): LayoutNode {
  const newState = _.cloneDeep(node);
  const path = findNodePathByUuid(newState, uuid);

  if (path) {
    const lodashPath = path.map(index => `children[${index}]`).join(".");
    const parentPath = lodashPath.split(".").slice(0, -1).join(".");
    const i = path[path.length - 1];
    const parent = parentPath ? _.get(newState, parentPath) : newState;
    if (parent && parent.children) {
      parent.children.splice(i, 1);
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
export function insertNodeAtPath(layoutNode: LayoutNode, path: number[], newNode: LayoutNode): LayoutNode {
  const newState = _.cloneDeep(layoutNode);

  if (path.length === 0) {
    throw new Error("Path must have at least one element to define where to insert the node.");
  }

  // Base case: if the path has only one element, insert the new node at the specified index
  if (path.length === 1) {
    newState.children = newState.children || [];
    newState.children.splice(path[0], 0, newNode);
    return newState;
  }

  // Recursive case: navigate down the path
  const [currentIndex, ...restOfPath] = path;
  newState.children = newState.children || [];
  if (!newState.children[currentIndex]) {
    throw new Error("Path must resolve to a node in the tree.");
  }

  // Recursively insert the node at the remaining path and update the child node
  newState.children[currentIndex] = insertNodeAtPath(newState.children[currentIndex], restOfPath, newNode);

  return newState;
}

/**
 * Move a node to a new path.
 * @param node - The root node of the layout.
 * @param uuid - The UUID of the node to move.
 * @param path - The path to move the node to.
 * @returns A deep clone of the `node` with the node matching the `uuid` moved to the `path`.
 */
export function moveNodeToPath(layoutNode: LayoutNode, uuid: string, path: number[]): LayoutNode {
  const child = findNodeByUuid(layoutNode, uuid);
  if (!child) {
    throw new Error(`Node with UUID ${uuid} not found.`);
  }
  // Make a clone of the node that is being moved.
  const clone = _.cloneDeep(child);
  // flag the original node for deletion
  child.uuid = child.uuid + "_remove";

  // Insert the clone at toPath
  const newState = insertNodeAtPath(layoutNode, path, clone);

  // Remove the original node by finding it by uuid (which is now `${child.uuid}_remove`)
  return removeNodeByUuid(newState, child.uuid);
}

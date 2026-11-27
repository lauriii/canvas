import { DropdownMenu } from '@radix-ui/themes';

import { buildCandidateTree } from './adapterSource';

import type { CandidateTreeNode, SlotCandidate } from './adapterSource';

// Renders one node of the field-candidate tree: a submenu when it has
// children, a selectable leaf otherwise. A node with both a candidate and
// children exposes the candidate as its own item inside the submenu (the same
// convention the field linker uses for a selectable parent).
const renderNode = (
  node: CandidateTreeNode,
  onSelect: (candidate: SlotCandidate) => void,
  keyPath: string,
) => {
  if (node.children && node.children.length > 0) {
    return (
      <DropdownMenu.Sub key={keyPath}>
        <DropdownMenu.SubTrigger>{node.label}</DropdownMenu.SubTrigger>
        <DropdownMenu.SubContent>
          {node.candidate && (
            <DropdownMenu.Item
              onClick={() => node.candidate && onSelect(node.candidate)}
            >
              {node.label}
            </DropdownMenu.Item>
          )}
          {node.children.map((child, index) =>
            renderNode(child, onSelect, `${keyPath}-${index}`),
          )}
        </DropdownMenu.SubContent>
      </DropdownMenu.Sub>
    );
  }
  return (
    <DropdownMenu.Item
      key={keyPath}
      onClick={() => node.candidate && onSelect(node.candidate)}
    >
      {node.label}
    </DropdownMenu.Item>
  );
};

/**
 * Renders a flat list of shape-matched field candidates as a nested set of
 * Radix Themes DropdownMenu items, splitting each candidate's arrow-path label
 * into submenus so shared prefixes collapse into shared branches instead of
 * repeating the path text.
 *
 * Must be rendered inside a DropdownMenu.Content or DropdownMenu.SubContent.
 */
const CandidateTreeMenuItems = ({
  candidates,
  onSelect,
}: {
  candidates: SlotCandidate[];
  onSelect: (candidate: SlotCandidate) => void;
}) => (
  <>
    {buildCandidateTree(candidates).map((node, index) =>
      renderNode(node, onSelect, `${index}`),
    )}
  </>
);

export default CandidateTreeMenuItems;

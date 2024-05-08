import type React from "react";
import "./TreeChild.css";
import TreeParent from "./TreeParent";
import type { LayoutNode } from "../layoutSlice";
import { deleteNode } from "../layoutSlice";
import { useAppDispatch } from "../../../app/hooks";

interface TreeChildProps {
  node: LayoutNode;
}
const TreeChild: React.FC<TreeChildProps> = props => {
  const { node } = props;
  const dispatch = useAppDispatch();

  function handleDeleteClick() {
    dispatch(deleteNode(node.uuid));
  }

  return (
    <li data-xb-uuid={node.uuid}>
      <div className="xb-tree-child-toolbar">
        <div>{node.name}</div>
        <button type="button" onClick={handleDeleteClick}>
          Del
        </button>
      </div>

      {node.children && <TreeParent node={node} />}
    </li>
  );
};

export default TreeChild;

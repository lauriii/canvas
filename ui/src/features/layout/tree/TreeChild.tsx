import type React from "react";
import "./TreeChild.css";
import TreeParent from "./TreeParent";
import type { LayoutNode } from "../layoutSlice";
import { deleteNode } from "../layoutSlice";
import {useAppDispatch, useAppSelector} from "../../../app/hooks";
import {selectModel} from "../../model/modelSlice";

interface TreeChildProps {
  node: LayoutNode;
}
const TreeChild: React.FC<TreeChildProps> = props => {
  const { node } = props;
  const model = useAppSelector(selectModel);
  const dispatch = useAppDispatch();

  function handleDeleteClick() {
    dispatch(deleteNode(node.uuid));
  }

  return (
    <li data-xb-uuid={node.uuid}>
      {node.type !== 'slot' && (
        <div className="xb-tree-child-toolbar">
          <div>{model[node.uuid]?.name}</div>
          <button type="button" onClick={handleDeleteClick}>
            Del
          </button>
        </div>
      )}

      {node.children && <TreeParent node={node} />}
    </li>
  );
};

export default TreeChild;

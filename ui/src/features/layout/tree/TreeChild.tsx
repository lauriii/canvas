import type React from "react";
import styles from "./TreeChild.module.css";
import TreeParent from "./TreeParent";
import type { LayoutNode } from "../layoutSlice";
import { deleteNode } from "../layoutSlice";
import {useAppDispatch, useAppSelector} from "../../../app/hooks";
import {selectModel} from "../../model/modelSlice";
import {IconButton} from "@radix-ui/themes";
import { TrashIcon } from '@radix-ui/react-icons'

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
        <div className={styles.treeChildToolbar}>
          <div>{model[node.uuid]?.name}</div>
          <IconButton size="1" type="button" onClick={handleDeleteClick}>
            <TrashIcon width="16" height="16" />
          </IconButton>
        </div>
      )}

      {node.children && <TreeParent node={node} />}
    </li>
  );
};

export default TreeChild;

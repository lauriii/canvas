import TreeParent from "./TreeParent";
import { useAppSelector } from "../../../app/hooks";
import { selectLayout } from "../layoutSlice";
import { selectDragging } from "../../ui/uiSlice";
import TreeDebug from "./TreeDebug";

const TreeView = () => {
  const layout = useAppSelector(selectLayout);
  const { isDragging } = useAppSelector(selectDragging);

  return (
    <div className={isDragging ? "list-dragging" : ""}>
      <TreeParent node={layout} />

      <TreeDebug />
    </div>
  );
};

export default TreeView;

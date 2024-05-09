import TreeParent from "./TreeParent";
import { useAppSelector } from "../../../app/hooks";
import { selectLayout } from "../layoutSlice";
import { selectDragging } from "../../ui/uiSlice";

const TreeView = () => {
  const layout = useAppSelector(selectLayout);
  const { isDragging } = useAppSelector(selectDragging);

  return <pre style={{}}>{JSON.stringify(layout, null, 2)}</pre>;
};

export default TreeView;

import TreeParent from "./TreeParent";
import { useAppSelector } from "../../../app/hooks";
import { selectLayout } from "../layoutSlice";
import { selectDragging } from "../layoutUISlice";
import TreeDebug from "./TreeDebug";
import { useEffect, useState } from "react";

const TreeView = () => {
  const layout = useAppSelector(selectLayout);
  const { isDragging } = useAppSelector(selectDragging);
  const [render, setRender] = useState(0);

  // useEffect(() => {
  //   setRender(prev => {
  //     return ++prev;
  //   });
  // }, [layout]);

  return (
    <div className={isDragging ? "list-dragging" : ""} data-key={render}>
      <TreeParent node={layout} />

      <TreeDebug />
    </div>
  );
};

export default TreeView;

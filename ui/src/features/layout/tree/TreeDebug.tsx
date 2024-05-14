import { useAppSelector } from "../../../app/hooks";
import { selectLayout } from "../layoutSlice";
import { selectDragging } from "../../ui/uiSlice";
import { selectModel } from "../../model/modelSlice";
import {useEffect} from "react";

const TreeDebug = () => {
  const model = useAppSelector(selectModel);
  const layout = useAppSelector(selectLayout);
  const draggingStatus = useAppSelector(selectDragging);

  useEffect(() => {console.log('Layout updated', layout)}, [layout]);
  useEffect(() => {console.log('Model updated', model)}, [model]);

  return <pre>{JSON.stringify(draggingStatus, null, 2)}</pre>;
};

export default TreeDebug;

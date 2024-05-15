import {useEffect} from "react";
import {useAppDispatch, useAppSelector} from "../../app/hooks";
import {useGetLayoutByIdQuery} from "../../services/layout";
import {LayoutNode, selectLayout, setNewLayout} from "./layoutSlice";
import {setModel} from "../model/modelSlice";

const Layout = () => {
  const dispatch = useAppDispatch();
  //TODO: Hardcoded node id:
  const { data: fetchedLayout, error, isLoading } = useGetLayoutByIdQuery('1');
  const layout = useAppSelector(selectLayout);

  useEffect(() => {
    if(fetchedLayout) {
      console.log(fetchedLayout);
      dispatch(setNewLayout({layout: fetchedLayout.layout}));
      dispatch(setModel({model: fetchedLayout.model}));
    }
  }, [fetchedLayout]);


  return null;
};

export default Layout;

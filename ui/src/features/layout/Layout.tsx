import { useEffect } from 'react';
import { useAppDispatch } from '@/app/hooks';
import { useGetLayoutByIdQuery } from '@/services/layout';
import { setLayoutModel } from './layoutModelSlice';

const Layout = () => {
  const dispatch = useAppDispatch();
  //TODO: Hardcoded node id:
  const { data: fetchedLayout } = useGetLayoutByIdQuery('1');

  useEffect(() => {
    if (fetchedLayout) {
      dispatch(
        setLayoutModel({
          layout: fetchedLayout.layout,
          model: fetchedLayout.model,
        }),
      );
    }
  }, [fetchedLayout, dispatch]);

  return null;
};

export default Layout;

import { useEffect } from 'react';
import { useAppDispatch } from '@/app/hooks';
import { useGetLayoutByIdQuery } from '@/services/layout';
import { setLayoutModel } from './layoutModelSlice';

const Layout = () => {
  const dispatch = useAppDispatch();
  //TODO: Hardcoded node id:
  const { data: fetchedLayout, error, isLoading } = useGetLayoutByIdQuery('1');

  useEffect(() => {
    if (fetchedLayout) {
      console.log(fetchedLayout);
      dispatch(setLayoutModel({ layout: fetchedLayout.layout, model: fetchedLayout.model }))
    }
  }, [fetchedLayout]);

  return null;
};

export default Layout;

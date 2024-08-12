import { useEffect } from 'react';
import { useAppDispatch, useAppSelector } from '@/app/hooks';
import { useGetLayoutByIdQuery } from '@/services/layout';
import { setLayoutModel } from './layoutModelSlice';
import { selectEntityId } from '@/features/configuration/configurationSlice';

const Layout = () => {
  const dispatch = useAppDispatch();
  const entityId = useAppSelector(selectEntityId);
  const { data: fetchedLayout } = useGetLayoutByIdQuery(entityId);

  useEffect(() => {
    if (fetchedLayout) {
      dispatch(
        setLayoutModel({
          layout: fetchedLayout.layout,
          model: fetchedLayout.model,
          initialized: true,
        }),
      );
    }
  }, [fetchedLayout, dispatch]);

  return null;
};

export default Layout;

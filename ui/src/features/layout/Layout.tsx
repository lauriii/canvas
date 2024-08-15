import { useEffect } from 'react';
import { useErrorBoundary } from 'react-error-boundary';
import { useAppDispatch, useAppSelector } from '@/app/hooks';
import { useGetLayoutByIdQuery } from '@/services/layout';
import { setLayoutModel } from './layoutModelSlice';
import { selectEntityId } from '@/features/configuration/configurationSlice';

const Layout = () => {
  const dispatch = useAppDispatch();
  const entityId = useAppSelector(selectEntityId);
  const { data: fetchedLayout, error } = useGetLayoutByIdQuery(entityId);
  const { showBoundary } = useErrorBoundary();

  useEffect(() => {
    if (error) {
      showBoundary(error);
    }
    if (fetchedLayout) {
      dispatch(
        setLayoutModel({
          layout: fetchedLayout.layout,
          model: fetchedLayout.model,
          initialized: true,
        }),
      );
    }
  }, [fetchedLayout, error, showBoundary, dispatch]);

  return null;
};

export default Layout;

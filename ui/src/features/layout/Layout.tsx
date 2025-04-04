import { useEffect } from 'react';
import { useErrorBoundary } from 'react-error-boundary';
import { useAppDispatch, useAppSelector } from '@/app/hooks';
import { useGetLayoutByIdQuery } from '@/services/componentAndLayout';
import { setInitialLayoutModel } from './layoutModelSlice';
import { selectEntityId } from '@/features/configuration/configurationSlice';

const Layout = () => {
  const dispatch = useAppDispatch();
  const entityId = useAppSelector(selectEntityId);
  const {
    data: fetchedLayout,
    error,
    isError,
    isFetching,
  } = useGetLayoutByIdQuery(entityId);
  const { showBoundary, resetBoundary } = useErrorBoundary();

  useEffect(() => {
    if (isError && error && !isFetching) {
      showBoundary(error);
      return;
    }
    // Reset the boundary so this component is re-rendered. Without this, the
    // error boundary will re-render while the layout is (re)fetching and as a
    // result it will require two clicks of the reset button in the alert to
    // allow the page to render.
    resetBoundary();
    if (fetchedLayout) {
      dispatch(
        setInitialLayoutModel({
          layout: fetchedLayout.layout,
          model: fetchedLayout.model,
          // We don't need to update the preview here - it is done in the layout
          // api's onQueryStarted method - @see componentAndLayout.ts
          updatePreview: false,
        }),
      );
    }
  }, [
    fetchedLayout,
    error,
    showBoundary,
    dispatch,
    resetBoundary,
    isError,
    isFetching,
  ]);

  return null;
};

export default Layout;

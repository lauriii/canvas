import { useParams } from 'react-router-dom';
import { _setReadOnlySelectedComponent } from '@/features/ui/uiSlice';
import { useAppDispatch } from '@/app/hooks';
import { useEffect } from 'react';

function useXbParams() {
  const dispatch = useAppDispatch();
  const params = useParams();

  useEffect(() => {
    dispatch(_setReadOnlySelectedComponent(params?.componentId));
  }, [dispatch, params]);

  return params;
}

export default useXbParams;

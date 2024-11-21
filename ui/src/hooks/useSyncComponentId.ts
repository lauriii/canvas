import { useEffect, useRef } from 'react';
import { useAppDispatch, useAppSelector } from '@/app/hooks';
import { useParams, useNavigate } from 'react-router-dom';
import {
  setSelectedComponent,
  selectSelectedComponent,
  unsetSelectedComponent,
} from '@/features/ui/uiSlice';

/**
 * useSyncComponentId
 *
 * This custom hook is responsible for synchronizing the selectedComponent state with the react-router /component/:componentId URL and vice versa.
 *
 * Specifically, it performs the following tasks:
 * 1. When the URL changes (i.e., the componentId in the URL changes), it updates the Redux state by
 *    dispatching an action to set the selected component.
 * 2. When the selected component in the Redux state changes, it updates the URL to reflect the currently
 *    selected component. If no component is selected, it navigates to the root URL `/`.
 *
 * The hook uses two refs (`prevComponentIdRef` and `prevSelectedComponentRef`) to keep track of the previous
 * componentId and selectedComponent values, ensuring that updates are only performed when necessary and preventing
 * an infinite loop.
 */

const useSyncComponentId = () => {
  const dispatch = useAppDispatch();
  const selectedComponent = useAppSelector(selectSelectedComponent);
  let { componentId } = useParams();
  const navigate = useNavigate();
  const prevComponentIdRef = useRef<string | undefined>();
  const prevSelectedComponentRef = useRef<string | undefined>();

  // Effect to update state when URL changes
  useEffect(() => {
    if (
      prevComponentIdRef.current !== componentId &&
      (componentId || prevComponentIdRef.current)
    ) {
      if (componentId) {
        dispatch(setSelectedComponent(componentId));
      } else {
        dispatch(unsetSelectedComponent());
      }
    }
    prevComponentIdRef.current = componentId;
  }, [componentId, dispatch]);

  // Effect to update URL when state changes
  useEffect(() => {
    if (prevSelectedComponentRef.current !== selectedComponent) {
      if (selectedComponent && componentId !== selectedComponent) {
        navigate(`editor/component/${selectedComponent}`);
      } else if (componentId !== selectedComponent) {
        navigate(`/editor/`);
      }
    }
    prevSelectedComponentRef.current = selectedComponent;
  }, [selectedComponent, navigate, componentId]);
};

export default useSyncComponentId;

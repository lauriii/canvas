import { useEffect } from 'react';
import { useErrorBoundary } from 'react-error-boundary';
import { useGetComponentsQuery } from '@/services/componentAndLayout';
import List from '@/components/list/List';
import { useAppSelector } from '@/app/hooks';
import {
  LayoutItemType,
  selectUniqueListId,
} from '@/features/ui/primaryPanelSlice';

const ComponentList = () => {
  const {
    data: components,
    error,
    isLoading,
  } = useGetComponentsQuery({
    mode: 'exclude',
    libraries: ['dynamic_components'],
  });
  const { showBoundary } = useErrorBoundary();
  const id = useAppSelector(selectUniqueListId);

  useEffect(() => {
    if (error) {
      showBoundary(error);
    }
  }, [error, showBoundary]);

  return (
    <List
      items={components}
      isLoading={isLoading}
      type={LayoutItemType.COMPONENT}
      label="Components"
      key={id}
    />
  );
};

export default ComponentList;

import { useEffect } from 'react';
import { useErrorBoundary } from 'react-error-boundary';
import List from '@/components/list/List';
import { useAppSelector } from '@/app/hooks';
import {
  LayoutItemType,
  selectUniqueListId,
} from '@/features/ui/primaryPanelSlice';
import { useGetFilteredComponentsQuery } from '@/hooks/useGetFilteredComponentsQuery';

const ComponentList = () => {
  const {
    filteredComponents: components,
    error,
    isLoading,
  } = useGetFilteredComponentsQuery({
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

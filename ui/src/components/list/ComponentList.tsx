import { useEffect } from 'react';
import { useErrorBoundary } from 'react-error-boundary';
import { useGetComponentsQuery } from '@/services/componentAndLayout';
import List from '@/components/list/List';

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

  useEffect(() => {
    if (error) {
      showBoundary(error);
    }
  }, [error, showBoundary]);

  return (
    <List
      items={components}
      isLoading={isLoading}
      type="component"
      label="Components"
    />
  );
};

export default ComponentList;

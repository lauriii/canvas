import { useEffect } from 'react';
import { useErrorBoundary } from 'react-error-boundary';
import { useGetComponentsQuery } from '@/services/components';
import List from '@/components/list/List';

const ComponentList = () => {
  const { data: components, error, isLoading } = useGetComponentsQuery();
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

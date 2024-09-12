import { useEffect } from 'react';
import { useErrorBoundary } from 'react-error-boundary';
import { useGetComponentsQuery } from '@/services/components';
import List from '@/components/list/List';
import { toArray } from 'lodash';

const ComponentList = () => {
  const { data: components, error, isLoading } = useGetComponentsQuery();
  const { showBoundary } = useErrorBoundary();

  useEffect(() => {
    if (error) {
      showBoundary(error);
    }
  }, [error, showBoundary]);

  const sortedComponents = components
    ? toArray(components).sort((a, b) => a.name.localeCompare(b.name))
    : {};

  return (
    <List
      items={sortedComponents}
      isLoading={isLoading}
      type="component"
      label="Components"
    />
  );
};

export default ComponentList;

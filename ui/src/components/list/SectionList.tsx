import { useEffect } from 'react';
import { useErrorBoundary } from 'react-error-boundary';
import List from '@/components/list/List';
import { useGetSectionsQuery } from '@/services/sections';

const SectionList = () => {
  const { data: sections, error, isLoading } = useGetSectionsQuery();
  const { showBoundary } = useErrorBoundary();

  useEffect(() => {
    if (error) {
      showBoundary(error);
    }
  }, [error, showBoundary]);

  return (
    <List
      items={sections}
      isLoading={isLoading}
      type="section"
      label="Section templates"
    />
  );
};

export default SectionList;

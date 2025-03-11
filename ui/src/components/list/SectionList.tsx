import { useEffect } from 'react';
import { useErrorBoundary } from 'react-error-boundary';
import List from '@/components/list/List';
import { useGetSectionsQuery } from '@/services/sections';
import { selectUniqueListId } from '@/features/ui/primaryPanelSlice';
import { useAppSelector } from '@/app/hooks';

const SectionList = () => {
  const { data: sections, error, isLoading } = useGetSectionsQuery();
  const { showBoundary } = useErrorBoundary();
  const id = useAppSelector(selectUniqueListId);

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
      key={id}
    />
  );
};

export default SectionList;

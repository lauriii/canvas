import { useEffect } from 'react';
import { useErrorBoundary } from 'react-error-boundary';
import List from '@/components/list/List';
import { useGetPatternsQuery } from '@/services/patterns';
import {
  LayoutItemType,
  selectUniqueListId,
} from '@/features/ui/primaryPanelSlice';
import { useAppSelector } from '@/app/hooks';

const PatternList = () => {
  const { data: patterns, error, isLoading } = useGetPatternsQuery();
  const { showBoundary } = useErrorBoundary();
  const id = useAppSelector(selectUniqueListId);

  useEffect(() => {
    if (error) {
      showBoundary(error);
    }
  }, [error, showBoundary]);

  return (
    <List
      items={patterns}
      isLoading={isLoading}
      type={LayoutItemType.PATTERN}
      label="Patterns"
      key={id}
    />
  );
};

export default PatternList;

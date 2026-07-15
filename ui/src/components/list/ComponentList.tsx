import { useEffect, useMemo } from 'react';
import { useErrorBoundary } from 'react-error-boundary';

import ListItem from '@/components/list/ListItem';
import { LayoutItemType } from '@/features/ui/primaryPanelSlice';
import {
  useGetComponentsQuery,
  useGetFoldersQuery,
} from '@/services/componentAndLayout';

import LibraryItemList from './LibraryItemList';

import type { CanvasComponent, ComponentsList } from '@/types/Component';
import type { FolderData } from './FolderList';

interface ComponentListProps {
  searchTerm: string;
  externalComponentsOnly: boolean;
}

const ComponentList = ({
  searchTerm,
  externalComponentsOnly,
}: ComponentListProps) => {
  const { data: components, error, isLoading } = useGetComponentsQuery();
  const {
    data: folders,
    error: foldersError,
    isLoading: foldersLoading,
  } = useGetFoldersQuery();
  const { showBoundary } = useErrorBoundary();
  const visibleComponents = useMemo(() => {
    if (!externalComponentsOnly) {
      return components;
    }

    return Object.fromEntries(
      Object.entries(components ?? {}).filter(
        ([, component]) =>
          component.library === 'primary_components' &&
          component.type === 'external',
      ),
    );
  }, [components, externalComponentsOnly]);

  useEffect(() => {
    if (error || foldersError) {
      showBoundary(error || foldersError);
    }
  }, [error, foldersError, showBoundary]);

  const renderItem = (item: CanvasComponent) => {
    return <ListItem item={item} type={LayoutItemType.COMPONENT} />;
  };

  return (
    <LibraryItemList<CanvasComponent>
      items={visibleComponents as ComponentsList}
      folders={folders as FolderData}
      isLoading={isLoading || foldersLoading}
      searchTerm={searchTerm}
      layoutType={LayoutItemType.COMPONENT}
      topLevelLabel="Components"
      itemType="component"
      renderItem={renderItem}
    />
  );
};

export default ComponentList;

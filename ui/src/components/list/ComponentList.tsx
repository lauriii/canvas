import { useEffect, useMemo } from 'react';
import { useErrorBoundary } from 'react-error-boundary';

import ListItem from '@/components/list/ListItem';
import { isPersonalizationComponentId } from '@/features/layout/personalizationUtils';
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
  visibility: ComponentVisibility;
}

export type ComponentVisibility =
  | 'all'
  | 'external-only'
  | 'non-external-only'
  | 'non-external-and-fallback-external';

const ComponentList = ({ searchTerm, visibility }: ComponentListProps) => {
  const { data: components, error, isLoading } = useGetComponentsQuery();
  const {
    data: folders,
    error: foldersError,
    isLoading: foldersLoading,
  } = useGetFoldersQuery();
  const { showBoundary } = useErrorBoundary();
  const visibleComponents = useMemo(() => {
    // The personalization switch/case pair is placed exclusively through the
    // variants menu, so it is filtered out of the library listing only.
    // Other code still reads these entries from the query cache for
    // metadata and version strings.
    const listableComponents = Object.fromEntries(
      Object.entries(components ?? {}).filter(
        ([, component]) => !isPersonalizationComponentId(component.id),
      ),
    );

    if (visibility === 'all') {
      return listableComponents;
    }

    return Object.fromEntries(
      Object.entries(listableComponents).filter(([, component]) => {
        if (visibility === 'external-only') {
          return (
            component.library === 'primary_components' &&
            component.type === 'external'
          );
        }
        if (visibility === 'non-external-only') {
          return (
            component.library !== 'primary_components' ||
            component.type !== 'external'
          );
        }
        return (
          component.library !== 'primary_components' ||
          component.type !== 'external' ||
          component.hasFallbackImplementation === true
        );
      }),
    );
  }, [components, visibility]);

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

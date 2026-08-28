import { useEffect, useMemo } from 'react';
import { useErrorBoundary } from 'react-error-boundary';
import { skipToken } from '@reduxjs/toolkit/query';

import ListItem from '@/components/list/ListItem';
import { LayoutItemType } from '@/features/ui/primaryPanelSlice';
import { useCanvasHeadlessSettings } from '@/hooks/useCanvasHeadlessSettings';
import {
  useGetComponentsQuery,
  useGetFoldersQuery,
} from '@/services/componentAndLayout';
import { useGetFrontendsQuery } from '@/services/headlessFrontends';
import { isMarkerComponentType } from '@/services/pageVariants';

import LibraryItemList from './LibraryItemList';

import type { CanvasComponent, ComponentsList } from '@/types/Component';
import type { FolderData } from './FolderList';

const normalizeFrontendUrl = (frontendUrl: string) => {
  const frontend = new URL(frontendUrl);
  return `${frontend.origin}${frontend.pathname === '/' ? '' : frontend.pathname}`;
};

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
  const headlessSettings = useCanvasHeadlessSettings();
  const { data: allComponents, error, isLoading } = useGetComponentsQuery();
  const {
    data: configuredFrontends,
    error: frontendsError,
    isLoading: frontendsLoading,
  } = useGetFrontendsQuery(headlessSettings ? undefined : skipToken);
  // Markers (e.g. the page variant "Page content" marker) are intrinsic
  // placeholders managed by Canvas: they are never offered in the library.
  // Memoized: a fresh object on every render would remount the whole list on
  // unrelated re-renders, dropping in-flight clicks on its menu items.
  const components = useMemo(
    () =>
      allComponents
        ? Object.fromEntries(
            Object.entries(allComponents).filter(
              ([id]) => !isMarkerComponentType(id),
            ),
          )
        : allComponents,
    [allComponents],
  );
  const {
    data: folders,
    error: foldersError,
    isLoading: foldersLoading,
  } = useGetFoldersQuery();
  const { showBoundary } = useErrorBoundary();
  const activeFrontendComponentIds = useMemo(() => {
    if (!headlessSettings) {
      return null;
    }
    const activeFrontend = configuredFrontends?.find(
      (frontend) =>
        normalizeFrontendUrl(frontend.url) === headlessSettings.frontendUrl,
    );
    return new Set(activeFrontend?.components ?? []);
  }, [configuredFrontends, headlessSettings]);
  const visibleComponents = useMemo(() => {
    if (visibility === 'all') {
      return components;
    }

    return Object.fromEntries(
      Object.entries(components ?? {}).filter(([, component]) => {
        if (visibility === 'external-only') {
          return (
            component.library === 'primary_components' &&
            component.type === 'external' &&
            (activeFrontendComponentIds === null ||
              activeFrontendComponentIds.has(component.id))
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
  }, [activeFrontendComponentIds, components, visibility]);

  useEffect(() => {
    if (error || foldersError || frontendsError) {
      showBoundary(error || foldersError || frontendsError);
    }
  }, [error, foldersError, frontendsError, showBoundary]);

  const renderItem = (item: CanvasComponent) => {
    return <ListItem item={item} type={LayoutItemType.COMPONENT} />;
  };

  return (
    <LibraryItemList<CanvasComponent>
      items={visibleComponents as ComponentsList}
      folders={folders as FolderData}
      isLoading={isLoading || foldersLoading || frontendsLoading}
      searchTerm={searchTerm}
      layoutType={LayoutItemType.COMPONENT}
      topLevelLabel="Components"
      itemType="component"
      renderItem={renderItem}
    />
  );
};

export default ComponentList;

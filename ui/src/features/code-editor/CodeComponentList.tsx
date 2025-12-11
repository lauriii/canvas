import { useEffect } from 'react';
import { useErrorBoundary } from 'react-error-boundary';

import LibraryItemList from '@/components/list/LibraryItemList';
import ListItem from '@/components/list/ListItem';
import { LayoutItemType } from '@/features/ui/primaryPanelSlice';
import {
  useGetCodeComponentsQuery,
  useGetFoldersQuery,
} from '@/services/componentAndLayout';

import type { FolderData } from '@/components/list/FolderList';
import type { CodeComponentSerialized } from '@/types/CodeComponent';

const CodeComponentList = ({ searchTerm }: { searchTerm: string }) => {
  const {
    data: codeComponents,
    error,
    isLoading,
  } = useGetCodeComponentsQuery();
  const {
    data: folders,
    error: foldersError,
    isLoading: foldersLoading,
  } = useGetFoldersQuery();
  const { showBoundary } = useErrorBoundary();

  useEffect(() => {
    if (error || foldersError) {
      showBoundary(error || foldersError);
    }
  }, [error, showBoundary, foldersError]);

  const renderItem = (component: CodeComponentSerialized & { id: string }) => (
    <ListItem item={component} type={LayoutItemType.CODE} />
  );
  // Map machineName to id for compatibility with LibraryItemList's generic
  const codeComponentsWithId = codeComponents
    ? Object.fromEntries(
        Object.entries(codeComponents).map(([key, component]) => [
          key,
          { ...component, id: component.machineName },
        ]),
      )
    : undefined;

  return (
    <LibraryItemList<CodeComponentSerialized & { id: string }>
      items={
        codeComponentsWithId as Record<
          string,
          CodeComponentSerialized & { id: string }
        >
      }
      folders={folders as FolderData}
      isLoading={isLoading || foldersLoading}
      searchTerm={searchTerm}
      layoutType={LayoutItemType.CODE}
      topLevelLabel="Code"
      itemType="js_component"
      renderItem={renderItem}
    />
  );
};

export default CodeComponentList;

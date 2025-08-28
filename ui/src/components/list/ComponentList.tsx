import { useEffect, useMemo } from 'react';
import { useErrorBoundary } from 'react-error-boundary';
import List from '@/components/list/List';
import { useAppSelector } from '@/app/hooks';
import {
  LayoutItemType,
  selectUniqueListId,
} from '@/features/ui/primaryPanelSlice';
import {
  useGetFoldersQuery,
  useGetComponentsQuery,
} from '@/services/componentAndLayout';

import FolderList, {
  folderfyComponents,
  sortFolderList,
} from '@/components/list/FolderList';
import type { ComponentsList } from '@/types/Component';

const ComponentList = () => {
  const { data: components, error, isLoading } = useGetComponentsQuery();
  const {
    data: folders,
    error: foldersError,
    isLoading: foldersLoading,
  } = useGetFoldersQuery({ status: false });
  const { showBoundary } = useErrorBoundary();
  const id = useAppSelector(selectUniqueListId);

  useEffect(() => {
    if (error || foldersError) {
      showBoundary(error || foldersError);
    }
  }, [error, foldersError, showBoundary]);

  const { topLevelComponents, folderComponents } = useMemo(
    () =>
      folderfyComponents(
        components,
        folders,
        isLoading,
        foldersLoading,
        'component',
      ),
    [components, folders, isLoading, foldersLoading],
  );
  const folderEntries = sortFolderList(folderComponents);

  return (
    <>
      {/* First, render any folders and the items they contain. */}
      {folderEntries.length > 0 &&
        folderEntries.map((folder, index) => {
          return (
            <FolderList key={index} folder={folder}>
              <List
                items={folder.items as ComponentsList}
                isLoading={foldersLoading}
                type={LayoutItemType.COMPONENT}
                label={`Components in folder ${folder.name}`}
                key={folder.id}
                inFolder={true}
              />
            </FolderList>
          );
        })}
      {/* Show if components are still loading (to show skeleton) or if there
          are folder-less components (to display the components). */}
      {(isLoading ||
        foldersLoading ||
        !!Object.keys(topLevelComponents || {}).length) && (
        <List
          items={topLevelComponents || {}}
          isLoading={isLoading || foldersLoading}
          type={LayoutItemType.COMPONENT}
          label="Components"
          key={id}
        />
      )}
    </>
  );
};

export default ComponentList;

import {
  useGetFoldersQuery,
  useUpdateFolderMutation,
} from '@/services/componentAndLayout';

import type { DragEndEvent } from '@dnd-kit/core';

export function useDropOnFolderHandler() {
  const { data: folders } = useGetFoldersQuery();
  const [updateFolder] = useUpdateFolderMutation();

  const handleFolderDrop = async (e: DragEndEvent) => {
    const { active, over } = e;
    if (over?.data?.current?.destination !== 'folder') {
      return;
    }
    if (!folders) {
      // Should not be possible to reach here, but just in case.
      throw new Error(
        'Folders data is not available, please wait and try again.',
      );
    }
    const componentId = active.id;
    const priorFolderId = folders.componentIndexedFolders?.[componentId];
    const priorFolder = folders.folders[priorFolderId];
    const newFolderId = over?.id;
    const newFolder = newFolderId ? folders.folders[newFolderId] : null;

    if (priorFolderId === newFolderId) {
      // Item was dropped back into the same folder
      return;
    }

    if (priorFolder) {
      const items = priorFolder.items || [];
      await updateFolder({
        id: priorFolder.id,
        changes: {
          name: priorFolder.name,
          items: items.filter((item: string) => item !== componentId),
          weight: priorFolder.weight,
        },
      });
    }

    if (newFolder) {
      const items = [...newFolder.items, componentId];
      await updateFolder({
        id: newFolderId,
        changes: {
          name: newFolder.name,
          items,
          weight: newFolder.weight,
        },
      });
    }
  };

  return { handleFolderDrop };
}

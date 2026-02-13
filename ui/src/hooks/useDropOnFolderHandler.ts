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
    const destination = over?.data?.current?.destination;
    if (!['folder', 'uncategorized'].includes(destination)) {
      return;
    }
    if (!folders) {
      throw new Error(
        'Folders data is not available, please wait and try again.',
      );
    }

    // Check if this is a folder being dragged (folder reordering).
    const activeData = active.data?.current;
    if (activeData?.type === 'folder') {
      // Dragging folders to uncategorized is intentionally unsupported.
      if (destination !== 'folder') {
        return;
      }

      const draggedFolderId = activeData.folderId;
      const targetFolderId = String(over?.id);

      if (draggedFolderId === targetFolderId) {
        // Folder was dropped on itself.
        return;
      }

      const draggedFolder = folders.folders[draggedFolderId];
      const targetFolder = folders.folders[targetFolderId];

      if (!draggedFolder || !targetFolder) {
        return;
      }

      // Get all folders of the same type and sort them by current weight.
      const folderType = draggedFolder.type;
      const allFoldersOfType = Object.entries(folders.folders)
        .filter(([, folder]) => folder.type === folderType)
        .sort((a, b) => (a[1].weight ?? 0) - (b[1].weight ?? 0));

      const draggedIndex = allFoldersOfType.findIndex(
        ([id]) => id === draggedFolderId,
      );
      const targetIndex = allFoldersOfType.findIndex(
        ([id]) => id === targetFolderId,
      );

      if (draggedIndex === -1 || targetIndex === -1) {
        return;
      }

      // Remove dragged folder and insert at target position.
      const [draggedEntry] = allFoldersOfType.splice(draggedIndex, 1);
      allFoldersOfType.splice(targetIndex, 0, draggedEntry);

      // Update weights for all reordered folders.
      const updatePromises = allFoldersOfType.map(([id, folder], index) => {
        if (folder.weight !== index) {
          return updateFolder({
            id,
            changes: {
              name: folder.name,
              items: folder.items || [],
              weight: index,
            },
          });
        }
        return null;
      });

      try {
        await Promise.all(updatePromises.filter(Boolean));
      } catch (error) {
        console.error('Failed to update folder order:', error);
        throw new Error('Failed to reorder folders. Please try again.');
      }

      return;
    }

    // Handle component/code being dropped into folder or uncategorized.
    const componentId = String(active.id);
    const priorFolderId = folders.componentIndexedFolders?.[componentId];
    const priorFolder = folders.folders[priorFolderId];
    const newFolderId = destination === 'folder' ? String(over?.id) : null;
    const newFolder = newFolderId ? folders.folders[newFolderId] : null;

    if (priorFolderId === newFolderId) {
      // Item was dropped back into the same folder.
      return;
    }

    if (priorFolder) {
      const items = priorFolder.items || [];
      try {
        await updateFolder({
          id: priorFolder.id,
          changes: {
            name: priorFolder.name,
            items: items.filter((item: string) => item !== componentId),
            weight: priorFolder.weight,
          },
        });
      } catch (error) {
        console.error('Failed to remove item from folder:', error);
        throw new Error('Failed to remove item from folder. Please try again.');
      }
    }

    if (newFolder) {
      const items = [...newFolder.items, componentId];
      try {
        await updateFolder({
          id: newFolder.id,
          changes: {
            name: newFolder.name,
            items,
            weight: newFolder.weight,
          },
        });
      } catch (error) {
        console.error('Failed to add item to folder:', error);
        throw new Error('Failed to add item to folder. Please try again.');
      }
    }
  };

  return { handleFolderDrop };
}

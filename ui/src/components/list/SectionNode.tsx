import SidebarNode from '@/components/sidebar/SidebarNode';
import type React from 'react';
import UnifiedMenu from '@/components/UnifiedMenu';
import { ContextMenu } from '@radix-ui/themes';
import styles from '@/features/code-editor/CodeComponentList.module.css';
import { useAppDispatch } from '@/app/hooks';
import { setDialogWithDataOpen } from '@/features/ui/dialogSlice';
import type { Section } from '@/types/Section';

const SectionNode: React.FC<{
  section: Section;
  onMenuOpenChange: (open: boolean) => void;
}> = (props) => {
  const { section, onMenuOpenChange } = props;
  const dispatch = useAppDispatch();

  const handleDeleteClick = (e: React.MouseEvent<HTMLDivElement>) => {
    e.stopPropagation();
    dispatch(
      setDialogWithDataOpen({
        operation: 'deleteSectionConfirm',
        data: section,
      }),
    );
  };

  const menuItems = (
    <UnifiedMenu.Item color="red" onClick={handleDeleteClick}>
      Delete
    </UnifiedMenu.Item>
  );

  return (
    <ContextMenu.Root key={section.id} onOpenChange={onMenuOpenChange}>
      <ContextMenu.Trigger>
        <SidebarNode
          title={section.name}
          variant="section"
          className={styles.listItem}
          dropdownMenuContent={
            <UnifiedMenu.Content menuType="dropdown">
              {menuItems}
            </UnifiedMenu.Content>
          }
          onMenuOpenChange={onMenuOpenChange}
        />
      </ContextMenu.Trigger>
      <UnifiedMenu.Content
        onClick={(e) => e.stopPropagation()}
        menuType="context"
        align="start"
        side="right"
      >
        {menuItems}
      </UnifiedMenu.Content>
    </ContextMenu.Root>
  );
};

export default SectionNode;

import SidebarNode from '@/components/sidePanel/SidebarNode';
import type React from 'react';
import UnifiedMenu from '@/components/UnifiedMenu';
import { ContextMenu } from '@radix-ui/themes';
import { useAppDispatch } from '@/app/hooks';
import { setDialogWithDataOpen } from '@/features/ui/dialogSlice';
import type { Pattern } from '@/types/Pattern';
import PermissionCheck from '@/components/PermissionCheck';

const PatternNode: React.FC<{
  pattern: Pattern;
  onMenuOpenChange: (open: boolean) => void;
  disabled: boolean;
}> = (props) => {
  const { pattern, onMenuOpenChange, disabled } = props;
  const dispatch = useAppDispatch();

  const handleDeleteClick = (e: React.MouseEvent<HTMLDivElement>) => {
    e.stopPropagation();
    dispatch(
      setDialogWithDataOpen({
        operation: 'deletePatternConfirm',
        data: pattern,
      }),
    );
  };

  const menuItems = (
    <PermissionCheck
      hasPermission="patterns"
      denied={
        <UnifiedMenu.Item disabled>No actions available</UnifiedMenu.Item>
      }
    >
      <UnifiedMenu.Item color="red" onClick={handleDeleteClick}>
        Delete pattern
      </UnifiedMenu.Item>
    </PermissionCheck>
  );

  return (
    <ContextMenu.Root key={pattern.id} onOpenChange={onMenuOpenChange}>
      <ContextMenu.Trigger>
        <SidebarNode
          title={pattern.name}
          variant="pattern"
          disabled={disabled}
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

export default PatternNode;

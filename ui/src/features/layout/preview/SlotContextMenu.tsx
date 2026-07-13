import { ContextMenu } from '@radix-ui/themes';

import { useAppDispatch, useAppSelector } from '@/app/hooks';
import { UnifiedMenu } from '@/components/UnifiedMenu';
import { findExposedSlotEntry } from '@/features/layout/exposedSlots';
import {
  selectExposedSlots,
  setExposedSlotDisabled,
} from '@/features/layout/layoutModelSlice';
import { setDialogWithDataOpen } from '@/features/ui/dialogSlice';
import useGetComponentName from '@/hooks/useGetComponentName';

import type React from 'react';
import type { ReactNode } from 'react';
import type { UnifiedMenuType } from '@/components/UnifiedMenu';
import type {
  ComponentNode,
  SlotNode,
} from '@/features/layout/layoutModelSlice';
import type {
  ExposeSlotDialogData,
  RemoveExposedSlotDialogData,
} from '@/features/ui/dialogSlice';

interface SlotContextMenuProps {
  children: ReactNode;
  slot: SlotNode;
  parentComponent: ComponentNode;
}

export const SlotContextMenuContent: React.FC<
  Pick<SlotContextMenuProps, 'slot' | 'parentComponent'> & {
    menuType?: UnifiedMenuType;
  }
> = ({ slot, parentComponent, menuType = 'context' }) => {
  const dispatch = useAppDispatch();
  const exposedSlots = useAppSelector(selectExposedSlots);
  const slotTitle = useGetComponentName(slot, parentComponent);
  const componentUuid = parentComponent.uuid;
  const slotName = slot.name;

  const exposed = findExposedSlotEntry(exposedSlots, componentUuid, slotName);

  const openExposeDialog = () => {
    const data: ExposeSlotDialogData = {
      mode: 'expose',
      componentUuid,
      slotName,
      slotTitle,
    };
    dispatch(setDialogWithDataOpen({ operation: 'exposeSlot', data }));
  };

  const openEditLabelDialog = () => {
    if (!exposed) {
      return;
    }
    const data: ExposeSlotDialogData = {
      mode: 'editLabel',
      componentUuid,
      slotName,
      slotTitle,
      alias: exposed.alias,
      label: exposed.definition.label,
    };
    dispatch(setDialogWithDataOpen({ operation: 'exposeSlot', data }));
  };

  const toggleDisabled = () => {
    if (!exposed) {
      return;
    }
    dispatch(
      setExposedSlotDisabled({
        alias: exposed.alias,
        disabled: !exposed.definition.disabled,
      }),
    );
  };

  const openRemoveDialog = () => {
    if (!exposed) {
      return;
    }
    const data: RemoveExposedSlotDialogData = {
      alias: exposed.alias,
      label: exposed.definition.label,
    };
    dispatch(
      setDialogWithDataOpen({ operation: 'removeExposedSlotConfirm', data }),
    );
  };

  return (
    <UnifiedMenu.Content
      aria-label={`Context menu for slot ${slotTitle}`}
      menuType={menuType}
      align="start"
      side="right"
    >
      <UnifiedMenu.Label>{slotTitle}</UnifiedMenu.Label>
      <UnifiedMenu.Separator />
      {!exposed && (
        <UnifiedMenu.Item onClick={openExposeDialog}>
          Expose slot
        </UnifiedMenu.Item>
      )}
      {exposed && (
        <>
          <UnifiedMenu.Item onClick={openEditLabelDialog}>
            Edit label
          </UnifiedMenu.Item>
          <UnifiedMenu.Item onClick={toggleDisabled}>
            {exposed.definition.disabled ? 'Enable' : 'Disable'}
          </UnifiedMenu.Item>
          <UnifiedMenu.Separator />
          <UnifiedMenu.Item color="red" onClick={openRemoveDialog}>
            Remove
          </UnifiedMenu.Item>
        </>
      )}
    </UnifiedMenu.Content>
  );
};

const SlotContextMenu: React.FC<SlotContextMenuProps> = ({
  children,
  slot,
  parentComponent,
}) => {
  return (
    <ContextMenu.Root>
      <ContextMenu.Trigger>{children}</ContextMenu.Trigger>
      <SlotContextMenuContent slot={slot} parentComponent={parentComponent} />
    </ContextMenu.Root>
  );
};

export default SlotContextMenu;

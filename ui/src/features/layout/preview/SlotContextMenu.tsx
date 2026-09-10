import { ContextMenu } from '@radix-ui/themes';

import { useAppDispatch, useAppSelector } from '@/app/hooks';
import { UnifiedMenu } from '@/components/UnifiedMenu';
import {
  findExposedSlotEntry,
  findSlotNestingConflict,
} from '@/features/layout/exposedSlots';
import {
  selectExposedSlots,
  selectLayout,
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
  const layout = useAppSelector(selectLayout);
  const slotTitle = useGetComponentName(slot, parentComponent);
  const componentUuid = parentComponent.uuid;
  const slotName = slot.name;

  const exposed = findExposedSlotEntry(exposedSlots, componentUuid, slotName);
  // Exposed slots must not nest; block the invalid exposure here instead of
  // letting the save fail on the ValidExposedSlot constraint.
  const nestingConflict = exposed
    ? null
    : findSlotNestingConflict(exposedSlots, layout, slot, componentUuid);

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
      {!exposed && !nestingConflict && (
        <UnifiedMenu.Item onClick={openExposeDialog}>
          Expose slot
        </UnifiedMenu.Item>
      )}
      {!exposed && nestingConflict && (
        <UnifiedMenu.Item
          disabled
          data-testid={`canvas-expose-slot-blocked-${componentUuid}/${slotName}`}
        >
          {nestingConflict.direction === 'inside'
            ? `Expose slot (inside exposed slot "${nestingConflict.definition.label}")`
            : `Expose slot (contains exposed slot "${nestingConflict.definition.label}")`}
        </UnifiedMenu.Item>
      )}
      {exposed && (
        <>
          <UnifiedMenu.Item onClick={openEditLabelDialog}>
            Edit label
          </UnifiedMenu.Item>
          <UnifiedMenu.Separator />
          <UnifiedMenu.Item onClick={openRemoveDialog}>Detach</UnifiedMenu.Item>
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

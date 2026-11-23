import { useParams } from 'react-router';
import { ExternalLinkIcon } from '@radix-ui/react-icons';
import { Flex } from '@radix-ui/themes';

import { useAppDispatch, useAppSelector } from '@/app/hooks';
import { UnifiedMenu } from '@/components/UnifiedMenu';
import {
  EXPOSED_SLOTS_ENTITY_TYPE_ID,
  EXPOSED_SLOTS_VIEW_MODE,
  findExposedSlotEntry,
  findSlotNestingConflict,
} from '@/features/layout/exposedSlots';
import {
  selectExposedSlots,
  selectLayout,
} from '@/features/layout/layoutModelSlice';
import { setDialogWithDataOpen } from '@/features/ui/dialogSlice';
import useEditFieldsUrl from '@/hooks/useEditFieldsUrl';
import useGetComponentName from '@/hooks/useGetComponentName';

import type React from 'react';
import type { UnifiedMenuType } from '@/components/UnifiedMenu';
import type {
  ComponentNode,
  SlotNode,
} from '@/features/layout/layoutModelSlice';
import type {
  ExposeSlotDialogData,
  RemoveExposedSlotDialogData,
} from '@/features/ui/dialogSlice';

interface SlotMenuProps {
  slot: SlotNode;
  parentComponent: ComponentNode;
}

/**
 * The slot's actions, for a slot menu or a slot submenu of its component's
 * menu (@see ComponentContextMenuContent).
 */
export const SlotMenuItems: React.FC<SlotMenuProps> = ({
  slot,
  parentComponent,
}) => {
  const dispatch = useAppDispatch();
  const exposedSlots = useAppSelector(selectExposedSlots);
  const layout = useAppSelector(selectLayout);
  const slotTitle = useGetComponentName(slot, parentComponent);
  const editFieldsUrl = useEditFieldsUrl();
  const { viewMode, entityType } = useParams();
  const componentUuid = parentComponent.uuid;
  const slotName = slot.name;

  const exposed = findExposedSlotEntry(exposedSlots, componentUuid, slotName);
  // Exposed slots must not nest; block the invalid exposure here instead of
  // letting the save fail on the ValidExposedSlot constraint.
  const nestingConflict = exposed
    ? null
    : findSlotNestingConflict(exposedSlots, layout, slot, componentUuid);
  // Only one entity type and one view mode can expose slots; other templates
  // can't enable editing.
  const isExposeBlocked =
    viewMode !== EXPOSED_SLOTS_VIEW_MODE ||
    entityType !== EXPOSED_SLOTS_ENTITY_TYPE_ID;

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
      fieldName: exposed.fieldName,
      label: exposed.definition.label,
    };
    dispatch(setDialogWithDataOpen({ operation: 'exposeSlot', data }));
  };

  const openRemoveDialog = () => {
    if (!exposed) {
      return;
    }
    const data: RemoveExposedSlotDialogData = {
      fieldName: exposed.fieldName,
      label: exposed.definition.label,
    };
    dispatch(
      setDialogWithDataOpen({ operation: 'removeExposedSlotConfirm', data }),
    );
  };

  return (
    <>
      {!exposed && isExposeBlocked && (
        <UnifiedMenu.Item
          disabled
          data-testid={`canvas-expose-slot-view-mode-blocked-${componentUuid}/${slotName}`}
        >
          Enable editing (only for content types in the Full content view mode)
        </UnifiedMenu.Item>
      )}
      {!exposed && !isExposeBlocked && !nestingConflict && (
        <UnifiedMenu.Item onClick={openExposeDialog}>
          Enable editing
        </UnifiedMenu.Item>
      )}
      {!exposed && !isExposeBlocked && nestingConflict && (
        <UnifiedMenu.Item
          disabled
          data-testid={`canvas-expose-slot-blocked-${componentUuid}/${slotName}`}
        >
          {nestingConflict.direction === 'inside'
            ? `Enable editing (inside editable slot "${nestingConflict.definition.label}")`
            : `Enable editing (contains editable slot "${nestingConflict.definition.label}")`}
        </UnifiedMenu.Item>
      )}
      {exposed && (
        <>
          <UnifiedMenu.Item onClick={openEditLabelDialog}>
            Edit label
          </UnifiedMenu.Item>
          {/* Slot fields are created here but deleted in Field UI. */}
          {editFieldsUrl && (
            <UnifiedMenu.Item
              onClick={() =>
                window.open(editFieldsUrl, '_blank', 'noopener,noreferrer')
              }
            >
              <Flex gap="2" align="center">
                Manage fields
                <ExternalLinkIcon />
              </Flex>
            </UnifiedMenu.Item>
          )}
          <UnifiedMenu.Separator />
          <UnifiedMenu.Item onClick={openRemoveDialog}>
            Disable editing
          </UnifiedMenu.Item>
        </>
      )}
    </>
  );
};

export const SlotContextMenuContent: React.FC<
  SlotMenuProps & { menuType?: UnifiedMenuType }
> = ({ slot, parentComponent, menuType = 'context' }) => {
  const slotTitle = useGetComponentName(slot, parentComponent);
  return (
    <UnifiedMenu.Content
      aria-label={`Context menu for slot ${slotTitle}`}
      menuType={menuType}
      align="start"
      side="right"
    >
      <UnifiedMenu.Label>{slotTitle}</UnifiedMenu.Label>
      <UnifiedMenu.Separator />
      <SlotMenuItems slot={slot} parentComponent={parentComponent} />
    </UnifiedMenu.Content>
  );
};

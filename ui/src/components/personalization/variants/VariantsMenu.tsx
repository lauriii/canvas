import { useState } from 'react';
import {
  closestCenter,
  DndContext,
  KeyboardSensor,
  PointerSensor,
  useSensor,
  useSensors,
} from '@dnd-kit/core';
import {
  arrayMove,
  SortableContext,
  sortableKeyboardCoordinates,
  verticalListSortingStrategy,
} from '@dnd-kit/sortable';
import { LayersIcon, PlusIcon } from '@radix-ui/react-icons';
import {
  Button,
  DropdownMenu,
  Flex,
  Popover,
  RadioGroup,
  Separator,
  Text,
} from '@radix-ui/themes';

import { useAppDispatch, useAppSelector } from '@/app/hooks';
import CreateVariantDialog from '@/components/personalization/variants/CreateVariantDialog';
import DeleteVariantDialog from '@/components/personalization/variants/DeleteVariantDialog';
import VariantRow from '@/components/personalization/variants/VariantRow';
import {
  personalizePage,
  promoteVariantToDefault,
  removeVariant,
  reorderVariants,
  selectLayout,
  selectModel,
  setVariantDisabled,
} from '@/features/layout/layoutModelSlice';
import {
  CASE_COMPONENT_ID,
  DEFAULT_VARIANT_ID,
  findRootSwitch,
  getCaseVariantId,
  getPreviewedVariant,
  getSwitchCases,
  getSwitchVariants,
  isCaseDisabled,
  SWITCH_COMPONENT_ID,
} from '@/features/layout/personalizationUtils';
import {
  DEFAULT_REGION,
  selectPreviewedVariants,
  setPreviewedVariant,
} from '@/features/ui/uiSlice';
import { useGetComponentsQuery } from '@/services/componentAndLayout';

import type { DragEndEvent } from '@dnd-kit/core';

/**
 * Topbar control for page-level personalization. When the content region has
 * no root switch it offers a single action that personalizes the page; once
 * personalized it lists the page variants in priority order.
 */
const VariantsMenu = () => {
  const dispatch = useAppDispatch();
  const layout = useAppSelector(selectLayout);
  const model = useAppSelector(selectModel);
  const previewedVariants = useAppSelector(selectPreviewedVariants);
  const { data: components } = useGetComponentsQuery();
  const [isCreateOpen, setCreateOpen] = useState(false);
  const [deleteCandidate, setDeleteCandidate] = useState<string | null>(null);
  const sensors = useSensors(
    useSensor(PointerSensor),
    useSensor(KeyboardSensor, {
      coordinateGetter: sortableKeyboardCoordinates,
    }),
  );

  const contentRegion = layout.find((region) => region.id === DEFAULT_REGION);
  const rootSwitch = findRootSwitch(contentRegion);

  if (!contentRegion) {
    return null;
  }

  if (!rootSwitch) {
    const switchComponent = components?.[SWITCH_COMPONENT_ID];
    const caseComponent = components?.[CASE_COMPONENT_ID];
    const canPersonalize = Boolean(switchComponent && caseComponent);

    const handlePersonalize = () => {
      if (!switchComponent || !caseComponent) {
        return;
      }
      dispatch(
        personalizePage({
          switchComponentType: `${switchComponent.id}@${switchComponent.version}`,
          caseComponentType: `${caseComponent.id}@${caseComponent.version}`,
        }),
      );
    };

    return (
      <DropdownMenu.Root>
        <DropdownMenu.Trigger>
          <Button variant="ghost" color="gray">
            <LayersIcon />
            Personalize
          </Button>
        </DropdownMenu.Trigger>
        <DropdownMenu.Content align="end">
          <DropdownMenu.Item
            disabled={!canPersonalize}
            onSelect={handlePersonalize}
          >
            Personalize this page
          </DropdownMenu.Item>
        </DropdownMenu.Content>
      </DropdownMenu.Root>
    );
  }

  const switchUuid = rootSwitch.uuid;
  const variants = getSwitchVariants(model, switchUuid);
  const cases = getSwitchCases(rootSwitch);
  const caseByVariantId = new Map(
    cases.map((caseNode) => [getCaseVariantId(model, caseNode), caseNode]),
  );
  const activeVariantId = getPreviewedVariant(previewedVariants, switchUuid);

  const handleDragEnd = (event: DragEndEvent) => {
    const { active, over } = event;
    if (!over || active.id === over.id) {
      return;
    }
    const oldIndex = variants.indexOf(String(active.id));
    const newIndex = variants.indexOf(String(over.id));
    if (oldIndex === -1 || newIndex === -1) {
      return;
    }
    // The default variant always stays last regardless of the drop position.
    const order = arrayMove(variants, oldIndex, newIndex).filter(
      (id) => id !== DEFAULT_VARIANT_ID,
    );
    order.push(DEFAULT_VARIANT_ID);
    dispatch(reorderVariants({ switchUuid, order }));
  };

  const handlePromote = (variantId: string) => {
    dispatch(promoteVariantToDefault({ switchUuid, variantId }));
    if (activeVariantId === variantId) {
      // The promoted case is now the default variant; follow it so the
      // preview keeps showing the same content.
      dispatch(
        setPreviewedVariant({ switchUuid, variantId: DEFAULT_VARIANT_ID }),
      );
    }
  };

  const handleToggleDisabled = (variantId: string) => {
    const caseNode = caseByVariantId.get(variantId);
    const disabled = caseNode ? isCaseDisabled(model, caseNode) : false;
    dispatch(
      setVariantDisabled({ switchUuid, variantId, disabled: !disabled }),
    );
  };

  const handleDeleteConfirmed = () => {
    if (!deleteCandidate) {
      return;
    }
    dispatch(removeVariant({ switchUuid, variantId: deleteCandidate }));
    if (activeVariantId === deleteCandidate) {
      dispatch(
        setPreviewedVariant({ switchUuid, variantId: DEFAULT_VARIANT_ID }),
      );
    }
    setDeleteCandidate(null);
  };

  return (
    <>
      <Popover.Root>
        <Popover.Trigger>
          <Button variant="surface" color="gray" aria-label="Manage variants">
            <LayersIcon />
            Variant: {activeVariantId}
          </Button>
        </Popover.Trigger>
        <Popover.Content align="end" size="1" width="280px">
          <Flex direction="column" gap="1">
            <Text size="1" color="gray" weight="medium">
              Page variants
            </Text>
            <RadioGroup.Root
              value={activeVariantId}
              onValueChange={(variantId) =>
                dispatch(setPreviewedVariant({ switchUuid, variantId }))
              }
            >
              <DndContext
                sensors={sensors}
                collisionDetection={closestCenter}
                onDragEnd={handleDragEnd}
              >
                <SortableContext
                  items={variants}
                  strategy={verticalListSortingStrategy}
                >
                  {variants.map((variantId) => {
                    const caseNode = caseByVariantId.get(variantId);
                    return (
                      <VariantRow
                        key={variantId}
                        variantId={variantId}
                        isDefault={variantId === DEFAULT_VARIANT_ID}
                        isDisabled={
                          caseNode ? isCaseDisabled(model, caseNode) : false
                        }
                        onPromote={() => handlePromote(variantId)}
                        onToggleDisabled={() => handleToggleDisabled(variantId)}
                        onDelete={() => setDeleteCandidate(variantId)}
                      />
                    );
                  })}
                </SortableContext>
              </DndContext>
            </RadioGroup.Root>
            <Separator size="4" my="1" />
            <Button variant="ghost" onClick={() => setCreateOpen(true)}>
              <PlusIcon />
              New variant
            </Button>
          </Flex>
        </Popover.Content>
      </Popover.Root>
      <CreateVariantDialog
        open={isCreateOpen}
        onOpenChange={setCreateOpen}
        switchUuid={switchUuid}
        variants={variants}
      />
      <DeleteVariantDialog
        variantId={deleteCandidate}
        onClose={() => setDeleteCandidate(null)}
        onConfirm={handleDeleteConfirmed}
      />
    </>
  );
};

export default VariantsMenu;

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
import { InfoCircledIcon, LayersIcon, PlusIcon } from '@radix-ui/react-icons';
import {
  AlertDialog,
  Button,
  Callout,
  Flex,
  Popover,
  RadioGroup,
  Separator,
  Text,
} from '@radix-ui/themes';

import { useAppDispatch, useAppSelector } from '@/app/hooks';
import CreateVariantDialog from '@/components/personalization/variants/CreateVariantDialog';
import DeleteVariantDialog from '@/components/personalization/variants/DeleteVariantDialog';
import PreviewAsVisitor from '@/components/personalization/variants/PreviewAsVisitor';
import VariantAudience from '@/components/personalization/variants/VariantAudience';
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
import { getDisplayNameForNode } from '@/features/layout/layoutUtils';
import {
  DEFAULT_VARIANT_ID,
  findRootSwitch,
  findSwitchNodes,
  getCaseSegmentIds,
  getCaseVariantId,
  getContentSlot,
  getP13nComponentTypes,
  getPreviewedVariant,
  getSwitchCases,
  getSwitchVariants,
  humanizeVariantId,
  isCaseDisabled,
} from '@/features/layout/personalizationUtils';
import {
  DEFAULT_REGION,
  selectPreviewedVariants,
  setPreviewedVariant,
} from '@/features/ui/uiSlice';
import { useGetComponentsQuery } from '@/services/componentAndLayout';

import type { DragEndEvent } from '@dnd-kit/core';
import type { ComponentNode } from '@/features/layout/layoutModelSlice';

const EXPLAINER_DISMISSED_KEY = 'canvas.personalization.explainerDismissed';

/**
 * One-time introduction to how variants are served, shown at the top of the
 * popover until dismissed. The dismissal persists per browser, matching how
 * other editor UI state (such as collapsed layers) uses localStorage.
 */
const FirstRunExplainer = () => {
  const [isDismissed, setDismissed] = useState(
    () => window.localStorage.getItem(EXPLAINER_DISMISSED_KEY) === 'true',
  );

  if (isDismissed) {
    return null;
  }

  const handleDismiss = () => {
    window.localStorage.setItem(EXPLAINER_DISMISSED_KEY, 'true');
    setDismissed(true);
  };

  return (
    <Callout.Root color="gray" size="1" data-testid="personalization-explainer">
      <Callout.Icon>
        <InfoCircledIcon />
      </Callout.Icon>
      <Callout.Text>
        Each variant targets an audience (segments).
        <br />
        Visitors see the first matching variant, top to bottom.
        <br />
        The Default variant is the fallback for everyone else.
      </Callout.Text>
      <Flex justify="end">
        <Button variant="ghost" color="gray" size="1" onClick={handleDismiss}>
          Dismiss
        </Button>
      </Flex>
    </Callout.Root>
  );
};

interface SwitchVariantsSectionProps {
  switchNode: ComponentNode;
  // Section header shown only when the layout has multiple switches.
  sectionLabel?: string;
  onRequestCreate: () => void;
  onRequestDelete: (variantId: string) => void;
}

/**
 * The variant rows of one switch: previewed-variant selection, priority
 * reordering, and the per-variant actions.
 */
const SwitchVariantsSection = ({
  switchNode,
  sectionLabel,
  onRequestCreate,
  onRequestDelete,
}: SwitchVariantsSectionProps) => {
  const dispatch = useAppDispatch();
  const model = useAppSelector(selectModel);
  const previewedVariants = useAppSelector(selectPreviewedVariants);
  const sensors = useSensors(
    useSensor(PointerSensor),
    useSensor(KeyboardSensor, {
      coordinateGetter: sortableKeyboardCoordinates,
    }),
  );

  const switchUuid = switchNode.uuid;
  const variants = getSwitchVariants(model, switchUuid);
  const cases = getSwitchCases(switchNode);
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

  return (
    <Flex
      direction="column"
      gap="1"
      data-testid={`variant-switch-section-${switchUuid}`}
    >
      {sectionLabel && (
        <Text size="1" weight="medium">
          {sectionLabel}
        </Text>
      )}
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
                  segmentIds={
                    caseNode ? getCaseSegmentIds(model, caseNode) : []
                  }
                  isDefault={variantId === DEFAULT_VARIANT_ID}
                  isDisabled={
                    caseNode ? isCaseDisabled(model, caseNode) : false
                  }
                  onPromote={() => handlePromote(variantId)}
                  onToggleDisabled={() => handleToggleDisabled(variantId)}
                  onDelete={() => onRequestDelete(variantId)}
                />
              );
            })}
          </SortableContext>
        </DndContext>
      </RadioGroup.Root>
      <Separator size="4" my="1" />
      <Button variant="ghost" onClick={onRequestCreate}>
        <PlusIcon />
        New variant
      </Button>
    </Flex>
  );
};

/**
 * Topbar control for personalization. When the layout has no switch it
 * offers a single action that personalizes the page; otherwise it lists the
 * variants of every switch in the layout, in priority order. With a single
 * switch the popover looks like a flat variant list; with multiple switches
 * each switch becomes a labeled section.
 */
const VariantsMenu = () => {
  const dispatch = useAppDispatch();
  const layout = useAppSelector(selectLayout);
  const model = useAppSelector(selectModel);
  const previewedVariants = useAppSelector(selectPreviewedVariants);
  const { data: components } = useGetComponentsQuery();
  const [isPersonalizeOpen, setPersonalizeOpen] = useState(false);
  const [createFor, setCreateFor] = useState<string | null>(null);
  const [deleteCandidate, setDeleteCandidate] = useState<{
    switchUuid: string;
    variantId: string;
  } | null>(null);

  const contentRegion = layout.find((region) => region.id === DEFAULT_REGION);
  const rootSwitch = findRootSwitch(contentRegion);
  const switches = findSwitchNodes(layout);

  if (!contentRegion) {
    return null;
  }

  if (switches.length === 0) {
    const p13nTypes = getP13nComponentTypes(components);

    const handlePersonalize = () => {
      if (!p13nTypes) {
        return;
      }
      dispatch(personalizePage(p13nTypes));
    };

    return (
      <>
        <Button
          variant="ghost"
          color="gray"
          disabled={!p13nTypes}
          onClick={() => setPersonalizeOpen(true)}
        >
          <LayersIcon />
          Personalize
        </Button>
        <AlertDialog.Root
          open={isPersonalizeOpen}
          onOpenChange={setPersonalizeOpen}
        >
          <AlertDialog.Content>
            <AlertDialog.Title>Personalize this page</AlertDialog.Title>
            <AlertDialog.Description size="2">
              This wraps the current page in a default variant. You can then add
              variants for specific audiences.
            </AlertDialog.Description>
            <Flex gap="3" mt="4" justify="end">
              <AlertDialog.Cancel>
                <Button variant="soft" color="gray">
                  Cancel
                </Button>
              </AlertDialog.Cancel>
              <AlertDialog.Action>
                <Button variant="solid" onClick={handlePersonalize}>
                  Personalize page
                </Button>
              </AlertDialog.Action>
            </Flex>
          </AlertDialog.Content>
        </AlertDialog.Root>
      </>
    );
  }

  const isMultiSwitch = switches.length > 1;
  // The trigger reflects the root switch when there is one, and the single
  // switch otherwise.
  const triggerSwitch = rootSwitch ?? switches[0];
  const triggerVariantId = getPreviewedVariant(
    previewedVariants,
    triggerSwitch.uuid,
  );

  const getSectionLabel = (switchNode: ComponentNode): string => {
    if (rootSwitch && switchNode.uuid === rootSwitch.uuid) {
      return 'Page';
    }
    // A component switch is named after the component it personalizes: the
    // first child of its first case.
    const firstCase = getSwitchCases(switchNode)[0];
    const firstChild = firstCase
      ? getContentSlot(firstCase)?.components[0]
      : undefined;
    return firstChild
      ? getDisplayNameForNode(firstChild, null, components)
      : 'Component';
  };

  const handleDeleteConfirmed = () => {
    if (!deleteCandidate) {
      return;
    }
    const { switchUuid, variantId } = deleteCandidate;
    dispatch(removeVariant({ switchUuid, variantId }));
    if (getPreviewedVariant(previewedVariants, switchUuid) === variantId) {
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
          <Button
            variant="surface"
            color="gray"
            aria-label="Manage variants"
            title={
              isMultiSwitch && !rootSwitch
                ? undefined
                : `Machine name: ${triggerVariantId}`
            }
          >
            <LayersIcon />
            {isMultiSwitch && !rootSwitch
              ? 'Variants'
              : `Variant: ${humanizeVariantId(triggerVariantId)}`}
          </Button>
        </Popover.Trigger>
        <Popover.Content align="end" size="1" width="280px">
          <Flex direction="column" gap="1">
            <FirstRunExplainer />
            <Text size="1" color="gray" weight="medium">
              {isMultiSwitch
                ? 'Variants'
                : rootSwitch
                  ? 'Page variants'
                  : `${getSectionLabel(triggerSwitch)} variants`}
            </Text>
            <Text size="1" color="gray">
              Visitors see the first variant whose audience matches, top to
              bottom.
            </Text>
            {switches.map((switchNode) => (
              <SwitchVariantsSection
                key={switchNode.uuid}
                switchNode={switchNode}
                sectionLabel={
                  isMultiSwitch ? getSectionLabel(switchNode) : undefined
                }
                onRequestCreate={() => setCreateFor(switchNode.uuid)}
                onRequestDelete={(variantId) =>
                  setDeleteCandidate({ switchUuid: switchNode.uuid, variantId })
                }
              />
            ))}
            <Separator size="4" my="1" />
            <PreviewAsVisitor getSwitchLabel={getSectionLabel} />
          </Flex>
        </Popover.Content>
      </Popover.Root>
      <CreateVariantDialog
        open={createFor !== null}
        onOpenChange={(open) => {
          if (!open) {
            setCreateFor(null);
          }
        }}
        switchUuid={createFor ?? ''}
        variants={createFor ? getSwitchVariants(model, createFor) : []}
      />
      <DeleteVariantDialog
        variantId={deleteCandidate?.variantId ?? null}
        onClose={() => setDeleteCandidate(null)}
        onConfirm={handleDeleteConfirmed}
      />
    </>
  );
};

export default VariantsMenu;

import { useMemo } from 'react';
import { useParams } from 'react-router';
import {
  InfoCircledIcon,
  LockClosedIcon,
  Pencil1Icon,
} from '@radix-ui/react-icons';
import { Box, Button, Callout, Flex, Text } from '@radix-ui/themes';

import { useAppDispatch, useAppSelector } from '@/app/hooks';
import PermissionCheck from '@/components/PermissionCheck';
import { findEnclosingExposedSlotAlias } from '@/features/layout/exposedSlots';
import {
  overrideSlotDefaultContent,
  selectExposedSlots,
  selectLayout,
  selectSlotOverrides,
} from '@/features/layout/layoutModelSlice';
import { selectSelectedComponentUuid } from '@/features/ui/uiSlice';
import useEditorNavigation from '@/hooks/useEditorNavigation';
import { useGetContentTemplatesQuery } from '@/services/componentAndLayout';

import type {
  TemplatesInBundle,
  TemplateViewMode,
} from '@/services/componentAndLayout';

type ContentTemplateList = Record<
  string,
  { label: string; bundles: TemplatesInBundle }
>;

/**
 * Finds the content template (entityType + bundle + full view mode) whose
 * exposed slots match the per-content editor's, so the "Edit template" jump has
 * a target. The client does not receive the bundle directly in per-content
 * mode, so it is resolved from the content-templates listing by matching the
 * exposed-slot aliases.
 */
const findMatchingTemplateViewMode = (
  templates: ContentTemplateList | undefined,
  entityType: string | undefined,
  aliases: string[],
): TemplateViewMode | undefined => {
  if (!templates || !entityType || !templates[entityType]) {
    return undefined;
  }
  const bundles = templates[entityType].bundles;
  for (const bundle of Object.values(bundles)) {
    for (const viewMode of Object.values(bundle.viewModes)) {
      const exposed = Object.keys(viewMode.exposed_slots ?? {});
      if (
        exposed.length > 0 &&
        aliases.every((alias) => exposed.includes(alias))
      ) {
        return viewMode;
      }
    }
  }
  return undefined;
};

/**
 * Per-content editing: the settings panel shown for a locked (template-owned)
 * component. It explains the component belongs to the template and offers a
 * permission-gated jump to the template editor.
 */
const LockedComponentPanel: React.FC = () => {
  const { entityType } = useParams();
  const dispatch = useAppDispatch();
  const exposedSlots = useAppSelector(selectExposedSlots);
  const layout = useAppSelector(selectLayout);
  const slotOverrides = useAppSelector(selectSlotOverrides);
  const selectedUuid = useAppSelector(selectSelectedComponentUuid);
  const { data: templates } = useGetContentTemplatesQuery();
  const { navigateToTemplateEditor } = useEditorNavigation();

  const viewMode = useMemo(
    () =>
      findMatchingTemplateViewMode(
        templates,
        entityType,
        Object.keys(exposedSlots ?? {}),
      ),
    [templates, entityType, exposedSlots],
  );

  // When the selected locked component is default content the template provides
  // inside a not-yet-overridden exposed slot, offer to override it per entity
  // ("Edit content"): a robust, discoverable home for the affordance (vs. a
  // floating overlay chip that cannot anchor to a wrapper-less SDC slot).
  const overrideAlias = useMemo(() => {
    if (!selectedUuid) {
      return undefined;
    }
    const enclosing = findEnclosingExposedSlotAlias(
      layout,
      exposedSlots,
      selectedUuid,
    );
    if (!enclosing || slotOverrides?.[enclosing.alias]?.overridden) {
      return undefined;
    }
    return enclosing.alias;
  }, [selectedUuid, layout, exposedSlots, slotOverrides]);

  return (
    <Box my="2" data-testid="canvas-locked-component-panel">
      <Callout.Root size="1" color="gray" variant="surface">
        <Callout.Icon>
          <LockClosedIcon />
        </Callout.Icon>
        <Callout.Text>
          {overrideAlias
            ? 'This is default content from the template. Edit it to customize it for this item, or edit the template to change it everywhere.'
            : 'This component belongs to the template and can’t be edited here. Edit the template to change it.'}
        </Callout.Text>
      </Callout.Root>
      {overrideAlias && (
        <Flex mt="3" justify="start">
          <Button
            size="1"
            onClick={() => dispatch(overrideSlotDefaultContent(overrideAlias))}
            className="canvas-button"
            data-testid="canvas-locked-panel-edit-content"
          >
            <Pencil1Icon />
            Edit content
          </Button>
        </Flex>
      )}
      <PermissionCheck
        hasPermission="contentTemplates"
        denied={
          <Callout.Root size="1" color="blue" variant="surface" mt="3">
            <Callout.Icon>
              <InfoCircledIcon />
            </Callout.Icon>
            <Callout.Text>
              You do not have permission to edit the template.
            </Callout.Text>
          </Callout.Root>
        }
      >
        <Flex mt="3" justify="start">
          <Button
            size="1"
            variant="soft"
            disabled={!viewMode}
            onClick={() => viewMode && navigateToTemplateEditor(viewMode)}
            className="canvas-button"
          >
            Edit template
          </Button>
        </Flex>
        {!viewMode && (
          <Text size="1" color="gray" as="p" mt="2">
            Open the template from the Templates list to edit it.
          </Text>
        )}
      </PermissionCheck>
    </Box>
  );
};

export default LockedComponentPanel;

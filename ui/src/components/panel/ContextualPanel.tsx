import { useEffect, useMemo, useState } from 'react';
import clsx from 'clsx';
import { useParams } from 'react-router';
import { Outlet } from 'react-router-dom';
import { ExternalLinkIcon, InfoCircledIcon } from '@radix-ui/react-icons';
import {
  Box,
  Button,
  Callout,
  Flex,
  ScrollArea,
  Tabs,
  Text,
} from '@radix-ui/themes';

import { useAppDispatch, useAppSelector } from '@/app/hooks';
import ErrorBoundary from '@/components/error/ErrorBoundary';
import PageDataForm from '@/components/PageDataForm';
import LockedSlotPanel from '@/components/panel/LockedSlotPanel';
import SlotExposePanel from '@/components/panel/SlotExposePanel';
import SlotUsagePanel from '@/components/panel/SlotUsagePanel';
import { setCurrentComponent } from '@/features/form/formStateSlice';
import {
  findExposedSlotEntry,
  getSlotHostComponentUuid,
  isLockedSlotRegion,
} from '@/features/layout/exposedSlots';
import {
  selectExposedSlots,
  selectIsPerContentMode,
  selectLayout,
  selectSlotDefaults,
  selectSlotOverrides,
} from '@/features/layout/layoutModelSlice';
import {
  findComponentByUuid,
  findSlotById,
} from '@/features/layout/layoutUtils';
import { buildEntityEditFormUrl } from '@/features/navigator/templatedContent';
import {
  EditorFrameContext,
  selectEditorFrameContext,
  selectIsMultiSelect,
  selectSelectedComponentUuid,
  selectSelection,
} from '@/features/ui/uiSlice';
import useGetComponentName from '@/hooks/useGetComponentName';
import useHidePanelClasses from '@/hooks/useHidePanelClasses';
import { getBaseUrl } from '@/utils/drupal-globals';

import type React from 'react';

import styles from './ContextualPanel.module.css';

const ContextualPanel: React.FC = () => {
  const selectedComponent = useAppSelector(selectSelectedComponentUuid);
  const isMultiSelect = useAppSelector(selectIsMultiSelect);
  const selection = useAppSelector(selectSelection);
  const dispatch = useAppDispatch();
  const editorFrameContext = useAppSelector(selectEditorFrameContext);
  const isTemplateContext = editorFrameContext === EditorFrameContext.TEMPLATE;
  const mainTabText = isTemplateContext ? 'Template data' : 'Page data';

  // Per-content mode (a templated entity with exposed slots): the panel gains
  // a Content tab ahead of Page data. Phase 1 links out to Drupal's own edit
  // form for the entity's content fields; Page data carries only page-level
  // metadata (the server trims the entity form).
  const isPerContentMode = useAppSelector(selectIsPerContentMode);
  const { entityType, entityId, bundle, viewMode } = useParams();
  const editFormUrl =
    isPerContentMode && entityType && entityId
      ? buildEntityEditFormUrl(getBaseUrl(), entityType, entityId)
      : null;
  const contentTemplateId =
    isTemplateContext && entityType && bundle && viewMode
      ? `${entityType}.${bundle}.${viewMode}`
      : undefined;

  // Per-content editing: a locked exposed slot is selected as a whole (its id,
  // which contains a slash, is not a routable component uuid, so it is held in
  // redux only). When that is the selection, the Settings tab shows the
  // LockedSlot panel (Unlock + template jump) instead of a component form.
  const layout = useAppSelector(selectLayout);
  const exposedSlots = useAppSelector(selectExposedSlots);
  const slotOverrides = useAppSelector(selectSlotOverrides);
  const slotDefaults = useAppSelector(selectSlotDefaults);
  const lockedSlotAlias = useMemo(() => {
    if (!isPerContentMode || !selectedComponent || !exposedSlots) {
      return undefined;
    }
    // A locked slot region is selected under its `${hostUuid}/${slotName}`
    // identity (slash-bearing, so held in redux only, not routable).
    const entry = Object.entries(exposedSlots).find(
      ([, definition]) =>
        `${definition.componentUuid}/${definition.slotName}` ===
        selectedComponent,
    );
    if (
      !entry ||
      !isLockedSlotRegion(entry[0], exposedSlots, slotOverrides, slotDefaults)
    ) {
      return undefined;
    }
    return entry[0];
  }, [
    isPerContentMode,
    selectedComponent,
    exposedSlots,
    slotOverrides,
    slotDefaults,
  ]);

  // Template editor: the selected slot node, if a slot is selected (its id
  // contains a slash, so it is held in redux only, not routable). A selected
  // slot's Settings tab replaces the component form with slot controls: usage
  // statistics if it is exposed, or an Expose slot action if it is not.
  const templateSlot = useMemo(
    () =>
      isTemplateContext && selectedComponent
        ? (findSlotById(layout, selectedComponent) ?? undefined)
        : undefined,
    [isTemplateContext, selectedComponent, layout],
  );
  const templateSlotEntry = useMemo(
    () =>
      templateSlot
        ? (findExposedSlotEntry(
            exposedSlots,
            getSlotHostComponentUuid(templateSlot),
            templateSlot.name,
          ) ?? undefined)
        : undefined,
    [templateSlot, exposedSlots],
  );
  const templateSlotHost = templateSlot
    ? findComponentByUuid(layout, getSlotHostComponentUuid(templateSlot))
    : null;
  const templateSlotTitle = useGetComponentName(
    templateSlot ?? null,
    templateSlotHost,
  );

  const [activePanel, setActivePanel] = useState('pageData');
  const offRightClasses = useHidePanelClasses('right');
  const [hidePanel, setHidePanel] = useState(false);

  useEffect(() => {
    if (selectedComponent) {
      // One component is selected
      dispatch(setCurrentComponent(selectedComponent));
      setActivePanel('settings');
    } else if (isMultiSelect) {
      // Multiple components are selected
      dispatch(setCurrentComponent(''));
      setActivePanel('settings');
    } else {
      // No component is selected
      dispatch(setCurrentComponent(''));
      setActivePanel('pageData');
    }
  }, [dispatch, selectedComponent, isMultiSelect]);

  useEffect(() => {
    if (isTemplateContext && !isMultiSelect && !selectedComponent) {
      setHidePanel(true);
    } else {
      setHidePanel(false);
    }
  }, [selectedComponent, isMultiSelect, isTemplateContext]);

  return (
    <Box
      data-testid="canvas-contextual-panel"
      pt="2"
      className={clsx(
        styles.contextualPanel,
        { [styles.hidePanel]: hidePanel },
        ...offRightClasses,
      )}
    >
      <Flex
        flexGrow="1"
        direction="column"
        height="100%"
        data-testid={`canvas-contextual-panel-${selectedComponent}`}
      >
        <ErrorBoundary>
          <Tabs.Root
            defaultValue={'pageData'}
            onValueChange={setActivePanel}
            value={activePanel}
            className={clsx(styles.tabRoot)}
          >
            <Tabs.List justify="start" mx="4" size="1">
              {!isTemplateContext && (
                <Tabs.Trigger
                  value="pageData"
                  data-testid="canvas-contextual-panel--page-data"
                >
                  {mainTabText}
                </Tabs.Trigger>
              )}
              {isPerContentMode && (
                <Tabs.Trigger
                  value="content"
                  data-testid="canvas-contextual-panel--content"
                >
                  Content
                </Tabs.Trigger>
              )}
              {(selectedComponent || isMultiSelect) && (
                <Tabs.Trigger
                  value="settings"
                  data-testid="canvas-contextual-panel--settings"
                >
                  Settings
                </Tabs.Trigger>
              )}
            </Tabs.List>
            <ScrollArea scrollbars="vertical" className={styles.scrollArea}>
              <Box px="4" width="100%">
                <Tabs.Content value={'settings'}>
                  {isMultiSelect && (
                    <Box my="2">
                      <Flex direction="column" gap="2">
                        <Text align="center" color="gray" my="3" size="1">
                          {selection.items.length} items selected
                        </Text>

                        <Flex gap="1" justify="center" align="center">
                          <Button
                            size="1"
                            disabled={!selection.consecutive}
                            onClick={() =>
                              alert(
                                'Copy functionality will be implemented later',
                              )
                            }
                            className="canvas-button"
                          >
                            Copy
                          </Button>
                          <Text size="1" color="gray">
                            or
                          </Text>
                          <Button
                            size="1"
                            disabled={!selection.consecutive}
                            onClick={() =>
                              alert(
                                'Save as Pattern functionality will be implemented later',
                              )
                            }
                            className="canvas-button"
                          >
                            Save as Pattern
                          </Button>
                        </Flex>
                        {!selection.consecutive && (
                          <Callout.Root
                            size="1"
                            color="blue"
                            variant="surface"
                            mt="4"
                          >
                            <Callout.Icon>
                              <InfoCircledIcon />
                            </Callout.Icon>
                            <Callout.Text>
                              Actions are only available when selecting adjacent
                              items in the layout
                            </Callout.Text>
                          </Callout.Root>
                        )}
                      </Flex>
                    </Box>
                  )}
                  {lockedSlotAlias ? (
                    <LockedSlotPanel alias={lockedSlotAlias} />
                  ) : templateSlot ? (
                    templateSlotEntry && contentTemplateId ? (
                      <SlotUsagePanel
                        contentTemplateId={contentTemplateId}
                        fieldName={templateSlotEntry.alias}
                      />
                    ) : (
                      <SlotExposePanel
                        slot={templateSlot}
                        slotTitle={templateSlotTitle}
                      />
                    )
                  ) : (
                    <ErrorBoundary title="An unexpected error has occurred while rendering the component's form.">
                      <Outlet />
                    </ErrorBoundary>
                  )}
                </Tabs.Content>
                {isPerContentMode && (
                  <Tabs.Content value={'content'}>
                    <Flex direction="column" gap="2" my="2" align="start">
                      <Text size="1" color="gray">
                        Content fields are edited in the Drupal edit form.
                      </Text>
                      {editFormUrl && (
                        <Button asChild size="1" className="canvas-button">
                          <a
                            href={editFormUrl}
                            target="_blank"
                            rel="noreferrer"
                            data-testid="canvas-content-tab-edit-form-link"
                          >
                            Edit content
                            <ExternalLinkIcon />
                          </a>
                        </Button>
                      )}
                    </Flex>
                  </Tabs.Content>
                )}
                {!isTemplateContext && (
                  <Tabs.Content
                    value={'pageData'}
                    forceMount={true}
                    hidden={activePanel !== 'pageData'}
                  >
                    {editorFrameContext === 'entity' && <PageDataForm />}
                  </Tabs.Content>
                )}
              </Box>
            </ScrollArea>
          </Tabs.Root>
        </ErrorBoundary>
      </Flex>
    </Box>
  );
};
export default ContextualPanel;

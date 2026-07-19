import { useEffect, useMemo, useState } from 'react';
import clsx from 'clsx';
import { useParams } from 'react-router';
import { Outlet, useNavigate } from 'react-router-dom';
import {
  DotsHorizontalIcon,
  ExternalLinkIcon,
  InfoCircledIcon,
} from '@radix-ui/react-icons';
import {
  Box,
  Button,
  Callout,
  DropdownMenu,
  Flex,
  IconButton,
  ScrollArea,
  Tabs,
  Text,
} from '@radix-ui/themes';
import { skipToken } from '@reduxjs/toolkit/query';

import { useAppDispatch, useAppSelector } from '@/app/hooks';
import ErrorBoundary from '@/components/error/ErrorBoundary';
import PageDataForm from '@/components/PageDataForm';
import LockedSlotPanel from '@/components/panel/LockedSlotPanel';
import SlotExposePanel from '@/components/panel/SlotExposePanel';
import SlotUsagePanel from '@/components/panel/SlotUsagePanel';
import StackedEntityForm from '@/components/stackedEntityForm/StackedEntityForm';
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
  selectPerContentTemplateInfo,
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
import { FOCUS_ENTITY_FORM_FIELD_EVENT } from '@/features/validation/entityFormViolations';
import useGetComponentName from '@/hooks/useGetComponentName';
import useHidePanelClasses from '@/hooks/useHidePanelClasses';
import { useGetPageLayoutQuery } from '@/services/componentAndLayout';
import { getBaseUrl, getCanvasSettings } from '@/utils/drupal-globals';

import type React from 'react';

import widgetStyles from '@/components/form/EntityFormWidgets.module.css';
import styles from './ContextualPanel.module.css';

interface ContextualPanelProps {
  /** Reports the active tab so the layout can widen the sidebar for Content. */
  onActivePanelChange?: (activePanel: string) => void;
}

const ContextualPanel: React.FC<ContextualPanelProps> = ({
  onActivePanelChange,
}) => {
  const selectedComponent = useAppSelector(selectSelectedComponentUuid);
  const isMultiSelect = useAppSelector(selectIsMultiSelect);
  const selection = useAppSelector(selectSelection);
  const dispatch = useAppDispatch();
  const editorFrameContext = useAppSelector(selectEditorFrameContext);
  const isTemplateContext = editorFrameContext === EditorFrameContext.TEMPLATE;
  const mainTabText = isTemplateContext ? 'Template data' : 'Page data';

  // Per-content mode (a templated entity): the panel gains a Content tab next
  // to Page data. Both tabs render disjoint slices of the same mounted entity
  // form: the server annotates content field widgets with
  // `data-canvas-form-partition="content"` and the active tab decides which
  // slice is visible, so react-hook-form state, auto-save, and undo carry
  // over unchanged. Page data carries only page-level metadata (title, URL
  // alias, and the form's sidebar groups).
  const isPerContentMode = useAppSelector(selectIsPerContentMode);
  const perContentTemplateInfo = useAppSelector(selectPerContentTemplateInfo);
  const navigate = useNavigate();
  const { entityType, entityId, bundle, viewMode } = useParams();
  // The open entity's bundle and its label, for the more-actions menu's
  // "View all" action (templated entities only).
  const contentBundle = perContentTemplateInfo?.bundle;
  const entityTypeLabels = getCanvasSettings()?.entityTypeLabels as
    | Record<string, Record<string, string> | string>
    | undefined;
  const typeLabels =
    entityType !== undefined ? entityTypeLabels?.[entityType] : undefined;
  const contentBundleLabel =
    contentBundle !== undefined && typeof typeLabels === 'object'
      ? (typeLabels[contentBundle] ?? contentBundle)
      : contentBundle;
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

  // Stacked reference editing: a referenced entity opened over the Content
  // tab (one level deep). The list of editable referenced entities comes with
  // the layout response.
  const [stackedTarget, setStackedTarget] = useState<{
    entityType: string;
    entityId: string;
    label: string;
  } | null>(null);
  const { data: layoutData } = useGetPageLayoutQuery(
    editorFrameContext === EditorFrameContext.ENTITY && entityId && entityType
      ? { entityId, entityType }
      : skipToken,
  );
  const referencedEditable = layoutData?.referencedEditable ?? [];
  // Leaving the entity or the Content tab closes the stack.
  useEffect(() => {
    setStackedTarget(null);
  }, [entityType, entityId]);
  useEffect(() => {
    if (activePanel !== 'content') {
      setStackedTarget(null);
    }
  }, [activePanel]);

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

  // Leaving per-content mode removes the Content tab; if it was the active
  // one, fall back to the default tab so the panel body is not left empty.
  useEffect(() => {
    if (!isPerContentMode) {
      setActivePanel((current) =>
        current === 'content' ? 'pageData' : current,
      );
    }
  }, [isPerContentMode]);

  useEffect(() => {
    onActivePanelChange?.(activePanel);
  }, [activePanel, onActivePanelChange]);

  // Jump-to-field requests (validation errors, review panel): activate the
  // tab whose partition holds the control, then scroll to and focus it. The
  // entity form is force-mounted, so the control exists even while hidden.
  useEffect(() => {
    const onFocusField = (event: Event) => {
      const fieldName = (event as CustomEvent<{ fieldName?: string }>).detail
        ?.fieldName;
      if (!fieldName) {
        return;
      }
      const escapedName = fieldName.replace(/\\/g, '\\\\').replace(/"/g, '\\"');
      // Entity-level constraint violations carry bare field names
      // (e.g. `title`) while the control registers under a delta path
      // (`title[0][value]`); fall back to the field's first control.
      const control =
        document.querySelector<HTMLElement>(
          `[data-testid="canvas-contextual-panel"] form [name="${escapedName}"]`,
        ) ??
        document.querySelector<HTMLElement>(
          `[data-testid="canvas-contextual-panel"] form [name^="${escapedName}["]`,
        );
      if (!control) {
        return;
      }
      const isContentField =
        control.closest('[data-canvas-form-partition="content"]') !== null;
      setActivePanel(
        isContentField && isPerContentMode ? 'content' : 'pageData',
      );
      // Wait a tick so the newly active tab's partition is visible before
      // scrolling and focusing.
      window.setTimeout(() => {
        control.scrollIntoView({ block: 'center' });
        control.focus();
      }, 0);
    };
    document.addEventListener(FOCUS_ENTITY_FORM_FIELD_EVENT, onFocusField);
    return () => {
      document.removeEventListener(FOCUS_ENTITY_FORM_FIELD_EVENT, onFocusField);
    };
  }, [isPerContentMode]);

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
            <Flex justify="between" align="center" mx="4" gap="2">
              <Tabs.List justify="start" size="1">
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
              {isPerContentMode &&
                (editFormUrl ||
                  referencedEditable.length > 0 ||
                  contentBundle) && (
                  <DropdownMenu.Root>
                    <DropdownMenu.Trigger>
                      <IconButton
                        size="1"
                        variant="ghost"
                        color="gray"
                        aria-label="More actions"
                        data-testid="canvas-content-tab-actions"
                      >
                        <DotsHorizontalIcon />
                      </IconButton>
                    </DropdownMenu.Trigger>
                    <DropdownMenu.Content align="end">
                      {editFormUrl && (
                        <DropdownMenu.Item asChild>
                          <a
                            href={editFormUrl}
                            target="_blank"
                            rel="noreferrer"
                            data-testid="canvas-content-tab-edit-form-link"
                          >
                            Edit in Drupal form
                            <ExternalLinkIcon />
                          </a>
                        </DropdownMenu.Item>
                      )}
                      {contentBundle && entityType && (
                        <DropdownMenu.Item
                          data-testid="canvas-content-tab-view-all"
                          onSelect={() =>
                            navigate(
                              `/content?type=${entityType}:${contentBundle}`,
                            )
                          }
                        >
                          View all {contentBundleLabel} content
                        </DropdownMenu.Item>
                      )}
                      {referencedEditable.length > 0 && (
                        <DropdownMenu.Separator />
                      )}
                      {referencedEditable.map((reference) => (
                        <DropdownMenu.Item
                          key={`${reference.entityType}-${reference.entityId}`}
                          onSelect={() => {
                            setActivePanel('content');
                            setStackedTarget(reference);
                          }}
                          data-testid={`canvas-content-tab-edit-reference-${reference.entityType}-${reference.entityId}`}
                        >
                          Edit {reference.label} ({reference.fieldLabel})
                        </DropdownMenu.Item>
                      ))}
                    </DropdownMenu.Content>
                  </DropdownMenu.Root>
                )}
            </Flex>
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
                    {stackedTarget && (
                      <ErrorBoundary title="An unexpected error has occurred while rendering the referenced entity's form.">
                        <StackedEntityForm
                          entityType={stackedTarget.entityType}
                          entityId={stackedTarget.entityId}
                          label={stackedTarget.label}
                          onClose={() => setStackedTarget(null)}
                        />
                      </ErrorBoundary>
                    )}
                  </Tabs.Content>
                )}
                {!isTemplateContext && (
                  <Tabs.Content
                    value={'pageData'}
                    forceMount={true}
                    hidden={
                      activePanel !== 'pageData' &&
                      !(
                        isPerContentMode &&
                        activePanel === 'content' &&
                        !stackedTarget
                      )
                    }
                    data-canvas-form-partition-view={
                      isPerContentMode && activePanel === 'content'
                        ? 'content'
                        : 'page-data'
                    }
                    className={clsx(styles.partitionedForm, widgetStyles.root)}
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

import { useEffect, useMemo, useState } from 'react';
import clsx from 'clsx';
import { Outlet, useParams } from 'react-router';
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
import InheritedSlotPanel from '@/components/panel/InheritedSlotPanel';
import SlotExposePanel from '@/components/panel/SlotExposePanel';
import SlotUsagePanel from '@/components/panel/SlotUsagePanel';
import CommentsPanel from '@/features/comments/CommentsPanel';
import {
  selectCommentsPanelOpen,
  setCommentsPanelOpen,
} from '@/features/comments/commentsSlice';
import CommentsTabLabel from '@/features/comments/CommentsTabLabel';
import useCommentSurface from '@/features/comments/useCommentSurface';
import { setCurrentComponent } from '@/features/form/formStateSlice';
import {
  findExposedSlotEntry,
  getSlotHostComponentUuid,
  isInheritedSlotRegion,
} from '@/features/layout/exposedSlots';
import {
  selectEditFormUrl,
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
import {
  EditorFrameContext,
  selectEditorFrameContext,
  selectIsMultiSelect,
  selectSelectedComponentUuid,
  selectSelection,
} from '@/features/ui/uiSlice';
import useGetComponentName from '@/hooks/useGetComponentName';
import useHidePanelClasses from '@/hooks/useHidePanelClasses';
import { PAGE_VARIANT_ENTITY_TYPE } from '@/services/pageVariants';
import { hasPermission } from '@/utils/permissions';

import type React from 'react';

import styles from './ContextualPanel.module.css';

const ContextualPanel: React.FC = () => {
  const selectedComponent = useAppSelector(selectSelectedComponentUuid);
  const isMultiSelect = useAppSelector(selectIsMultiSelect);
  const selection = useAppSelector(selectSelection);
  const dispatch = useAppDispatch();
  const editorFrameContext = useAppSelector(selectEditorFrameContext);
  const { entityId, entityType, bundle, viewMode } = useParams();
  const isTemplateContext = editorFrameContext === EditorFrameContext.TEMPLATE;
  // Page variants are config entities without entity form fields, so like
  // templates they have no "Page data" tab: the panel only shows component
  // settings.
  const hasPageDataTab =
    !isTemplateContext && entityType !== PAGE_VARIANT_ENTITY_TYPE;
  const mainTabText = isTemplateContext ? 'Template data' : 'Page data';

  // For a templated entity the panel gets a Content tab, which links to
  // Drupal's edit form for the content fields. Page data only shows the
  // fields Canvas keeps in its own form.
  const isPerContentMode = useAppSelector(selectIsPerContentMode);
  const editFormUrl = useAppSelector(selectEditFormUrl);
  const contentTemplateId =
    isTemplateContext && entityType && bundle && viewMode
      ? `${entityType}.${bundle}.${viewMode}`
      : undefined;

  // Per-content editing: an inherited exposed slot is selected as a whole (its
  // id, which contains a slash, is not a routable component uuid, so it is held
  // in redux only). When that is the selection, the Settings tab shows the
  // InheritedSlot panel (Override) instead of a component form.
  const layout = useAppSelector(selectLayout);
  const exposedSlots = useAppSelector(selectExposedSlots);
  const slotOverrides = useAppSelector(selectSlotOverrides);
  const slotDefaults = useAppSelector(selectSlotDefaults);
  const inheritedSlotFieldName = useMemo(() => {
    if (!isPerContentMode || !selectedComponent || !exposedSlots) {
      return undefined;
    }
    const [hostUuid, slotName] = selectedComponent.split('/');
    const entry = slotName
      ? findExposedSlotEntry(exposedSlots, hostUuid, slotName)
      : null;
    if (
      !entry ||
      !isInheritedSlotRegion(
        entry.fieldName,
        exposedSlots,
        slotOverrides,
        slotDefaults,
      )
    ) {
      return undefined;
    }
    return entry.fieldName;
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
  // statistics and a Disable editing action if it is exposed, or an Enable
  // editing action if it is not.
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
  // The tab is only offered on a surface the comment API accepts.
  const { hasSurface: hasCommentSurface } = useCommentSurface();
  const canViewComments = hasPermission('viewComments') && hasCommentSurface;
  const commentsOpen =
    useAppSelector(selectCommentsPanelOpen) && canViewComments;
  // While open, Comments wins over the selection-driven tab, so selecting a
  // component to comment on does not switch away from it.
  const currentTab = commentsOpen ? 'comments' : activePanel;

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

  // The panel must not stay on a tab that is no longer rendered.
  useEffect(() => {
    if (!canViewComments) {
      dispatch(setCommentsPanelOpen(false));
    }
  }, [canViewComments, dispatch]);

  useEffect(() => {
    if (!hasPageDataTab && !isMultiSelect && !selectedComponent) {
      setHidePanel(true);
    } else {
      setHidePanel(false);
    }
  }, [selectedComponent, isMultiSelect, hasPageDataTab]);

  // Leaving per-content mode removes the Content tab; if it was the active
  // one, fall back to the default tab so the panel body is not left empty.
  useEffect(() => {
    if (!isPerContentMode) {
      setActivePanel((current) =>
        current === 'content' ? 'pageData' : current,
      );
    }
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
            onValueChange={(value) => {
              dispatch(setCommentsPanelOpen(value === 'comments'));
              if (value !== 'comments') {
                setActivePanel(value);
              }
            }}
            value={currentTab}
            className={clsx(styles.tabRoot)}
          >
            <Tabs.List justify="start" mx="4" size="1">
              {hasPageDataTab && (
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
              {canViewComments && (
                <Tabs.Trigger
                  value="comments"
                  data-testid="canvas-contextual-panel--comments"
                >
                  <CommentsTabLabel />
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
                  {inheritedSlotFieldName ? (
                    <InheritedSlotPanel fieldName={inheritedSlotFieldName} />
                  ) : templateSlot ? (
                    templateSlotEntry && contentTemplateId ? (
                      <SlotUsagePanel
                        contentTemplateId={contentTemplateId}
                        fieldName={templateSlotEntry.fieldName}
                        label={templateSlotEntry.definition.label}
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
                    <Flex direction="column" gap="2" my="5" align="start">
                      <Text size="1" color="gray">
                        Fields that are part of the template are edited in the
                        CMS.
                      </Text>
                      {editFormUrl && (
                        <Button
                          asChild
                          size="1"
                          className={clsx(
                            'canvas-button',
                            styles.externalLinkButton,
                          )}
                        >
                          <a
                            href={editFormUrl}
                            target="_blank"
                            rel="noreferrer"
                            data-testid="canvas-content-tab-edit-form-link"
                          >
                            Edit CMS content
                            <ExternalLinkIcon />
                          </a>
                        </Button>
                      )}
                    </Flex>
                  </Tabs.Content>
                )}
                {hasPageDataTab && (
                  <Tabs.Content
                    value={'pageData'}
                    forceMount={true}
                    // This tab is force-mounted, so it is hidden against
                    // the tab on screen.
                    hidden={currentTab !== 'pageData'}
                  >
                    {editorFrameContext === 'entity' && (
                      <PageDataForm key={`${entityType}:${entityId}`} />
                    )}
                  </Tabs.Content>
                )}
                {canViewComments && (
                  <Tabs.Content value={'comments'}>
                    <ErrorBoundary>
                      <CommentsPanel />
                    </ErrorBoundary>
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

import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import ScaleToFitIcon from '@assets/icons/justify-stretch.svg?react';
import { ExternalLinkIcon, Pencil1Icon } from '@radix-ui/react-icons';
import {
  Badge,
  Box,
  Button,
  Flex,
  IconButton,
  Spinner,
  Tabs,
  Text,
  Tooltip,
} from '@radix-ui/themes';

import { useAppDispatch, useAppSelector } from '@/app/hooks';
import ZoomControl from '@/components/zoom/ZoomControl';
import ViewportSelector from '@/features/layout/preview/ViewportSelector';
import {
  editorViewPortZoomIn,
  editorViewPortZoomOut,
  scaleValues,
  selectEditorViewPortScale,
  selectViewportMinHeight,
  selectViewportWidth,
  setEditorFrameViewPort,
} from '@/features/ui/uiSlice';
import { buildFullPagePreviewDocument } from '@/features/versionComparison/pageVersionPreview';

import type React from 'react';

import styles from './VersionComparisonPage.module.css';

const EMPTY_HTML = '<main></main>';
const PREVIEW_FIT_PADDING = 96;
const PREVIEW_DRAG_THRESHOLD = 3;
const WHEEL_ZOOM_SENSITIVITY = 0.001;

type PageVersionId = 'published' | 'new';

export type PageVersionSelection = PageVersionId | undefined;

type PageVersionScrollElement = HTMLElement;

type PageVersionScrollAreas = Record<
  PageVersionId,
  PageVersionScrollElement | null
>;

type ScrollPosition = {
  scrollLeft: number;
  scrollTop: number;
};

const isEditableKeyboardTarget = (target: EventTarget | null): boolean => {
  if (!(target instanceof HTMLElement)) {
    return false;
  }

  return (
    ['INPUT', 'TEXTAREA', 'SELECT'].includes(target.tagName) ||
    target.isContentEditable ||
    target.closest('[contenteditable="true"]') !== null ||
    target.tagName === 'DEEP-CHAT'
  );
};

export interface PageVersionContent {
  html: string;
  updated?: string;
  loading?: boolean;
  changed?: boolean;
}

export interface PageVersionComparisonViewProps {
  entityId: string;
  entityType: string;
  publishedVersion: PageVersionContent;
  newVersion: PageVersionContent;
  publishedVersionTitle?: string;
  newVersionTitle?: string;
  selectedVersion?: PageVersionSelection;
  onSelectVersion?: (version: PageVersionId) => void;
  onPublishedPreviewClick?: () => void;
  onNewPreviewClick?: () => void;
  onNewEditClick?: () => void;
  showPreviewActions?: boolean;
}

const buildPageVersionPreviewUrl = (
  entityType: string,
  entityId: string,
  published: boolean,
): string => {
  const basePath = window.location.pathname.split(/\/(?:conflict|review)\b/)[0];
  const versionParam = published ? '?version=published' : '';
  return `${window.location.origin}${basePath}/version-preview/${entityType}/${entityId}/full${versionParam}`;
};

const syncComparisonScroll = (
  sourceVersion: PageVersionId,
  sourceScrollArea: PageVersionScrollElement,
  scrollAreas: PageVersionScrollAreas,
  ignoredScrollPositions: Map<PageVersionScrollElement, ScrollPosition>,
) => {
  const ignoredScrollPosition = ignoredScrollPositions.get(sourceScrollArea);
  if (ignoredScrollPosition) {
    ignoredScrollPositions.delete(sourceScrollArea);
    if (
      Math.abs(sourceScrollArea.scrollLeft - ignoredScrollPosition.scrollLeft) <
        0.5 &&
      Math.abs(sourceScrollArea.scrollTop - ignoredScrollPosition.scrollTop) <
        0.5
    ) {
      return;
    }
  }

  const targetVersion: PageVersionId =
    sourceVersion === 'published' ? 'new' : 'published';
  const targetScrollArea = scrollAreas[targetVersion];
  if (!targetScrollArea) {
    return;
  }

  const sourceMaxLeft =
    sourceScrollArea.scrollWidth - sourceScrollArea.clientWidth;
  const sourceMaxTop =
    sourceScrollArea.scrollHeight - sourceScrollArea.clientHeight;
  const targetMaxLeft =
    targetScrollArea.scrollWidth - targetScrollArea.clientWidth;
  const targetMaxTop =
    targetScrollArea.scrollHeight - targetScrollArea.clientHeight;

  const nextLeft =
    sourceMaxLeft > 0 && targetMaxLeft > 0
      ? (sourceScrollArea.scrollLeft / sourceMaxLeft) * targetMaxLeft
      : sourceScrollArea.scrollLeft;
  const nextTop =
    sourceMaxTop > 0 && targetMaxTop > 0
      ? (sourceScrollArea.scrollTop / sourceMaxTop) * targetMaxTop
      : sourceScrollArea.scrollTop;

  if (
    Math.abs(targetScrollArea.scrollLeft - nextLeft) < 0.5 &&
    Math.abs(targetScrollArea.scrollTop - nextTop) < 0.5
  ) {
    return;
  }

  ignoredScrollPositions.set(targetScrollArea, {
    scrollLeft: nextLeft,
    scrollTop: nextTop,
  });
  targetScrollArea.scrollLeft = nextLeft;
  targetScrollArea.scrollTop = nextTop;
};

export const PageVersionComparisonView = ({
  entityId,
  entityType,
  publishedVersion,
  newVersion,
  publishedVersionTitle = 'Published version',
  newVersionTitle = 'New version',
  selectedVersion,
  onSelectVersion,
  onPublishedPreviewClick,
  onNewPreviewClick,
  onNewEditClick,
  showPreviewActions = true,
}: PageVersionComparisonViewProps) => {
  const dispatch = useAppDispatch();
  const handlePublishedPreview =
    onPublishedPreviewClick ??
    (() =>
      window.open(
        buildPageVersionPreviewUrl(entityType, entityId, true),
        '_blank',
      ));
  const handleNewPreview =
    onNewPreviewClick ??
    (() =>
      window.open(
        buildPageVersionPreviewUrl(entityType, entityId, false),
        '_blank',
      ));
  const [tab, setTab] = useState('visual');
  const publishedContentHtml = useMemo(
    () => buildFullPagePreviewDocument(publishedVersion.html || EMPTY_HTML),
    [publishedVersion.html],
  );
  const newContentHtml = useMemo(
    () => buildFullPagePreviewDocument(newVersion.html || EMPTY_HTML),
    [newVersion.html],
  );
  const visualScrollAreasRef = useRef<PageVersionScrollAreas>({
    published: null,
    new: null,
  });
  const ignoredScrollPositionsRef = useRef(
    new Map<PageVersionScrollElement, ScrollPosition>(),
  );

  const handleVisualScrollAreaMount = useCallback(
    (version: PageVersionId, node: PageVersionScrollElement | null) => {
      visualScrollAreasRef.current[version] = node;
    },
    [],
  );

  const handleVisualScroll = useCallback(
    (
      sourceVersion: PageVersionId,
      sourceScrollArea: PageVersionScrollElement,
    ) => {
      syncComparisonScroll(
        sourceVersion,
        sourceScrollArea,
        visualScrollAreasRef.current,
        ignoredScrollPositionsRef.current,
      );
    },
    [],
  );

  useEffect(() => {
    if (tab !== 'visual') {
      return;
    }

    const handleKeyDown = (event: KeyboardEvent) => {
      if (isEditableKeyboardTarget(event.target)) {
        return;
      }

      if (event.code === 'Equal' || event.code === 'NumpadAdd') {
        event.preventDefault();
        dispatch(editorViewPortZoomIn());
        return;
      }

      if (event.code === 'Minus' || event.code === 'NumpadSubtract') {
        event.preventDefault();
        dispatch(editorViewPortZoomOut());
      }
    };

    window.addEventListener('keydown', handleKeyDown, true);

    return () => {
      window.removeEventListener('keydown', handleKeyDown, true);
    };
  }, [dispatch, tab]);

  return (
    <Tabs.Root value={tab} onValueChange={setTab} className={styles.tabRoot}>
      <Flex justify="between" align="center" className={styles.toolbar}>
        <Tabs.List size="1" className={styles.tabList}>
          <Tabs.Trigger className={styles.tabTrigger} value="visual">
            Visual
          </Tabs.Trigger>
        </Tabs.List>
        {tab === 'visual' && <ViewportSelector />}
      </Flex>
      <Tabs.Content value="visual" className={styles.tabContent}>
        <ComparisonGrid>
          <PreviewCard
            title={publishedVersionTitle}
            updated={publishedVersion.updated}
            version="published"
            selected={selectedVersion === 'published'}
            onSelect={onSelectVersion}
            onPreviewClick={handlePublishedPreview}
            showPreviewAction={showPreviewActions}
          >
            <VisualFrame
              html={publishedContentHtml}
              loading={!!publishedVersion.loading}
              version="published"
              onScrollAreaMount={handleVisualScrollAreaMount}
              onScrollAreaScroll={handleVisualScroll}
            />
          </PreviewCard>
          <PreviewCard
            title={newVersionTitle}
            updated={newVersion.updated}
            changed={!!newVersion.changed}
            version="new"
            selected={selectedVersion === 'new'}
            onSelect={onSelectVersion}
            onPreviewClick={handleNewPreview}
            onEditClick={onNewEditClick}
            showPreviewAction={showPreviewActions}
          >
            <VisualFrame
              html={newContentHtml}
              loading={!!newVersion.loading}
              version="new"
              onScrollAreaMount={handleVisualScrollAreaMount}
              onScrollAreaScroll={handleVisualScroll}
            />
          </PreviewCard>
        </ComparisonGrid>
      </Tabs.Content>
    </Tabs.Root>
  );
};

const ComparisonGrid = ({ children }: { children: React.ReactNode }) => (
  <div className={styles.comparisonGrid}>{children}</div>
);

const findClosestPreviewScaleValue = (desiredScale: number) => {
  const filteredScales = scaleValues.filter(
    (value) => value.scale <= desiredScale - 0.01,
  );

  if (filteredScales.length > 0) {
    return filteredScales.reduce((previous, current) =>
      current.scale > previous.scale ? current : previous,
    );
  }

  return scaleValues[0];
};

interface PreviewCardProps {
  title: string;
  updated?: string;
  children: React.ReactNode;
  version: PageVersionId;
  selected?: boolean;
  changed?: boolean;
  onSelect?: (version: PageVersionId) => void;
  onPreviewClick?: () => void;
  onEditClick?: () => void;
  showPreviewAction?: boolean;
}

const PreviewCard = ({
  title,
  updated,
  children,
  version,
  selected = false,
  changed = false,
  onSelect,
  onPreviewClick,
  onEditClick,
  showPreviewAction = true,
}: PreviewCardProps) => {
  const cardClassName = [
    styles.card,
    onSelect ? styles.cardSelectable : undefined,
    selected ? styles.cardSelected : undefined,
  ]
    .filter(Boolean)
    .join(' ');
  const headerClassName = [
    styles.cardHeader,
    selected ? styles.cardHeaderSelected : undefined,
  ]
    .filter(Boolean)
    .join(' ');

  const handleSelect = () => {
    onSelect?.(version);
  };

  const handleSelectButtonClick = (
    event: React.MouseEvent<HTMLButtonElement>,
  ) => {
    event.stopPropagation();
    handleSelect();
  };

  const handlePreviewButtonClick = (
    event: React.MouseEvent<HTMLButtonElement>,
  ) => {
    event.stopPropagation();
    onPreviewClick?.();
  };
  const handleEditButtonClick = (
    event: React.MouseEvent<HTMLButtonElement>,
  ) => {
    event.stopPropagation();
    onEditClick?.();
  };

  return (
    <section
      className={cardClassName}
      data-testid={`conflict-${version}-version-card`}
      onClick={onSelect ? handleSelect : undefined}
    >
      <div className={headerClassName}>
        <Box>
          <Text as="p" size="2" weight="medium">
            {title}
          </Text>
          {updated && (
            <Text as="p" size="1" color="gray">
              {updated}
            </Text>
          )}
        </Box>
        <Flex align="center" gap="3" className={styles.cardHeaderActions}>
          {onEditClick && (
            <Button
              variant="ghost"
              color="blue"
              size="1"
              onClick={handleEditButtonClick}
              className={styles.cardEditButton}
            >
              <Pencil1Icon />
              Edit
            </Button>
          )}
          {showPreviewAction && (
            <Tooltip content="Open preview in new tab">
              <IconButton
                variant="ghost"
                color="gray"
                size="1"
                aria-label="Open preview in new tab"
                onClick={handlePreviewButtonClick}
                style={{ cursor: 'pointer' }}
              >
                <ExternalLinkIcon />
              </IconButton>
            </Tooltip>
          )}
          {changed && (
            <Badge color="yellow" radius="small">
              Changed
            </Badge>
          )}
        </Flex>
        {onSelect && (
          <button
            type="button"
            aria-pressed={selected}
            aria-label={`Select ${title}`}
            className={styles.cardSelectButton}
            onClick={handleSelectButtonClick}
          />
        )}
      </div>
      <div className={styles.cardBody}>{children}</div>
    </section>
  );
};

const VisualFrame = ({
  html,
  loading,
  version,
  onScrollAreaMount,
  onScrollAreaScroll,
}: {
  html: string;
  loading: boolean;
  version: PageVersionId;
  onScrollAreaMount?: (
    version: PageVersionId,
    node: PageVersionScrollElement | null,
  ) => void;
  onScrollAreaScroll?: (
    version: PageVersionId,
    scrollArea: PageVersionScrollElement,
  ) => void;
}) => {
  const previewScale = useAppSelector(selectEditorViewPortScale);
  const previewWidth = useAppSelector(selectViewportWidth);
  const previewMinHeight = useAppSelector(selectViewportMinHeight);
  const previewScrollAreaRef = useRef<HTMLDivElement | null>(null);
  const previewFrameWrapperRef = useRef<HTMLDivElement | null>(null);
  const previewFrameRef = useRef<HTMLIFrameElement | null>(null);
  const dragStateRef = useRef<{
    pointerId: number;
    startX: number;
    startY: number;
    scrollLeft: number;
    scrollTop: number;
    didDrag: boolean;
  }>();
  const suppressNextClickRef = useRef(false);
  const [previewContentHeight, setPreviewContentHeight] = useState<number>();
  const [previewDocumentVersion, setPreviewDocumentVersion] = useState(0);
  const [isDraggingPreview, setIsDraggingPreview] = useState(false);
  const dispatch = useAppDispatch();

  const handleScrollAreaRef = useCallback(
    (node: HTMLDivElement | null) => {
      previewScrollAreaRef.current = node;
      onScrollAreaMount?.(version, node);
    },
    [onScrollAreaMount, version],
  );

  const handleScroll = useCallback(
    (event: React.UIEvent<HTMLDivElement>) => {
      onScrollAreaScroll?.(version, event.currentTarget);
    },
    [onScrollAreaScroll, version],
  );

  const handleWheel = useCallback(
    (event: React.WheelEvent<HTMLDivElement>) => {
      if (!event.ctrlKey && !event.metaKey) {
        return;
      }

      event.preventDefault();
      event.stopPropagation();

      const minScale = scaleValues[0].scale;
      const maxScale = scaleValues[scaleValues.length - 1].scale;
      const scaleDelta = -event.deltaY * WHEEL_ZOOM_SENSITIVITY * previewScale;
      const nextScale = Math.max(
        minScale,
        Math.min(maxScale, previewScale + scaleDelta),
      );

      dispatch(setEditorFrameViewPort({ scale: nextScale }));
    },
    [dispatch, previewScale],
  );

  const updatePreviewHeight = useCallback(() => {
    const document = previewFrameRef.current?.contentDocument;
    if (!document) {
      return;
    }

    const nextHeight = Math.ceil(
      Math.max(
        document.documentElement?.scrollHeight ?? 0,
        document.documentElement?.offsetHeight ?? 0,
        document.body?.scrollHeight ?? 0,
        document.body?.offsetHeight ?? 0,
        previewMinHeight ?? 0,
      ),
    );
    if (!Number.isFinite(nextHeight) || nextHeight <= 0) {
      return;
    }

    setPreviewContentHeight((currentHeight) =>
      currentHeight === nextHeight ? currentHeight : nextHeight,
    );
  }, [previewMinHeight]);

  const handleFrameLoad = useCallback(() => {
    setPreviewDocumentVersion((version) => version + 1);
    updatePreviewHeight();
  }, [updatePreviewHeight]);

  useEffect(() => {
    setPreviewContentHeight(undefined);
  }, [html, previewMinHeight, previewWidth]);

  useEffect(() => {
    if (loading) {
      return;
    }

    const document = previewFrameRef.current?.contentDocument;
    if (!document) {
      return;
    }

    let animationFrame: number | undefined;
    const schedulePreviewHeightUpdate = () => {
      if (animationFrame !== undefined) {
        window.cancelAnimationFrame(animationFrame);
      }
      animationFrame = window.requestAnimationFrame(updatePreviewHeight);
    };

    schedulePreviewHeightUpdate();
    const resizeObserver =
      typeof ResizeObserver === 'undefined'
        ? undefined
        : new ResizeObserver(schedulePreviewHeightUpdate);
    resizeObserver?.observe(document.documentElement);
    if (document.body) {
      resizeObserver?.observe(document.body);
    }

    const images = Array.from(document.images);
    images.forEach((image) => {
      image.addEventListener('load', schedulePreviewHeightUpdate);
      image.addEventListener('error', schedulePreviewHeightUpdate);
    });

    return () => {
      if (animationFrame !== undefined) {
        window.cancelAnimationFrame(animationFrame);
      }
      resizeObserver?.disconnect();
      images.forEach((image) => {
        image.removeEventListener('load', schedulePreviewHeightUpdate);
        image.removeEventListener('error', schedulePreviewHeightUpdate);
      });
    };
  }, [
    html,
    loading,
    previewDocumentVersion,
    previewWidth,
    updatePreviewHeight,
  ]);

  const previewFrameHeight =
    Math.max(previewMinHeight ?? 0, previewContentHeight ?? 0) || undefined;
  const previewScaledWidth =
    previewWidth && previewScale ? previewWidth * previewScale : undefined;
  const previewScaledHeight =
    previewFrameHeight && previewScale
      ? previewFrameHeight * previewScale
      : undefined;

  const endDrag = useCallback((event: React.PointerEvent<HTMLDivElement>) => {
    const dragState = dragStateRef.current;
    if (!dragState || dragState.pointerId !== event.pointerId) {
      return;
    }

    if (
      event.currentTarget.hasPointerCapture?.(event.pointerId) &&
      event.currentTarget.releasePointerCapture
    ) {
      event.currentTarget.releasePointerCapture(event.pointerId);
    }
    suppressNextClickRef.current = dragState.didDrag;
    dragStateRef.current = undefined;
    setIsDraggingPreview(false);
  }, []);

  const handlePointerDown = useCallback(
    (event: React.PointerEvent<HTMLDivElement>) => {
      if (event.button !== 0 || loading) {
        return;
      }

      dragStateRef.current = {
        pointerId: event.pointerId,
        startX: event.clientX,
        startY: event.clientY,
        scrollLeft: event.currentTarget.scrollLeft,
        scrollTop: event.currentTarget.scrollTop,
        didDrag: false,
      };
      event.currentTarget.setPointerCapture?.(event.pointerId);
      setIsDraggingPreview(true);
      event.preventDefault();
    },
    [loading],
  );

  const handlePointerMove = useCallback(
    (event: React.PointerEvent<HTMLDivElement>) => {
      const dragState = dragStateRef.current;
      if (!dragState || dragState.pointerId !== event.pointerId) {
        return;
      }

      const deltaX = event.clientX - dragState.startX;
      const deltaY = event.clientY - dragState.startY;
      if (
        Math.abs(deltaX) > PREVIEW_DRAG_THRESHOLD ||
        Math.abs(deltaY) > PREVIEW_DRAG_THRESHOLD
      ) {
        dragState.didDrag = true;
      }

      event.currentTarget.scrollLeft = dragState.scrollLeft - deltaX;
      event.currentTarget.scrollTop = dragState.scrollTop - deltaY;
      onScrollAreaScroll?.(version, event.currentTarget);
    },
    [onScrollAreaScroll, version],
  );

  const handleClick = useCallback((event: React.MouseEvent<HTMLDivElement>) => {
    if (!suppressNextClickRef.current) {
      return;
    }

    suppressNextClickRef.current = false;
    event.stopPropagation();
    event.preventDefault();
  }, []);

  return (
    <div className={styles.visualSurface}>
      <div
        className={styles.previewScrollArea}
        ref={handleScrollAreaRef}
        data-testid="canvas-version-preview-scroll-area"
        data-dragging={isDraggingPreview ? 'true' : undefined}
        onClick={handleClick}
        onPointerCancel={endDrag}
        onPointerDown={handlePointerDown}
        onPointerMove={handlePointerMove}
        onPointerUp={endDrag}
        onScroll={handleScroll}
        onWheel={handleWheel}
      >
        {loading ? (
          <Spinner />
        ) : (
          <div
            className={styles.previewFrameSizer}
            style={
              {
                ...(previewScaledWidth
                  ? { width: `${previewScaledWidth}px` }
                  : {}),
                ...(previewScaledHeight
                  ? {
                      height: `${previewScaledHeight}px`,
                      minHeight: `${previewScaledHeight}px`,
                    }
                  : {}),
              } as React.CSSProperties
            }
          >
            <div
              ref={previewFrameWrapperRef}
              className={styles.previewFrameWrapper}
              style={
                {
                  '--page-version-preview-scale': previewScale,
                  ...(previewWidth ? { width: `${previewWidth}px` } : {}),
                  ...(previewFrameHeight
                    ? {
                        height: `${previewFrameHeight}px`,
                        minHeight: `${previewFrameHeight}px`,
                      }
                    : {}),
                } as React.CSSProperties
              }
            >
              <iframe
                ref={previewFrameRef}
                title="Page version preview"
                className={styles.previewFrame}
                scrolling="no"
                srcDoc={html}
                onLoad={handleFrameLoad}
              />
            </div>
          </div>
        )}
      </div>
      <PageVersionPreviewControls
        previewScrollAreaRef={previewScrollAreaRef}
        previewFrameWrapperRef={previewFrameWrapperRef}
      />
    </div>
  );
};

interface PageVersionPreviewControlsProps {
  previewScrollAreaRef: React.RefObject<HTMLDivElement | null>;
  previewFrameWrapperRef: React.RefObject<HTMLDivElement | null>;
}

const PageVersionPreviewControls = ({
  previewScrollAreaRef,
  previewFrameWrapperRef,
}: PageVersionPreviewControlsProps) => {
  const dispatch = useAppDispatch();

  const handleScaleToFit = () => {
    const previewScrollArea = previewScrollAreaRef.current;
    const previewFrameWrapper = previewFrameWrapperRef.current;

    if (!previewScrollArea || !previewFrameWrapper) {
      dispatch(setEditorFrameViewPort({ scale: 1 }));
      return;
    }

    const availableWidth = Math.max(
      previewScrollArea.clientWidth - PREVIEW_FIT_PADDING,
      0,
    );
    const availableHeight = Math.max(
      previewScrollArea.clientHeight - PREVIEW_FIT_PADDING,
      0,
    );
    const frameWidth = previewFrameWrapper.offsetWidth;
    const frameHeight = previewFrameWrapper.offsetHeight;

    if (!availableWidth || !availableHeight || !frameWidth || !frameHeight) {
      dispatch(setEditorFrameViewPort({ scale: 1 }));
      return;
    }

    const scaleToFit = Math.min(
      availableWidth / frameWidth,
      availableHeight / frameHeight,
    );
    const closestScale = findClosestPreviewScaleValue(scaleToFit);

    dispatch(
      setEditorFrameViewPort({
        scale: closestScale.scale < 1 ? closestScale.scale : 1,
      }),
    );
  };

  return (
    <Flex
      align="center"
      gap="2"
      className={styles.previewControls}
      data-testid="canvas-version-preview-controls"
      onClick={(event) => event.stopPropagation()}
      onKeyDown={(event) => event.stopPropagation()}
    >
      <Tooltip side="bottom" content="Scale to fit">
        <Button
          size="1"
          onClick={handleScaleToFit}
          color="gray"
          variant="surface"
          highContrast
          className={styles.previewControlButton}
          aria-label="Scale to fit"
          data-testid="page-version-scale-to-fit"
        >
          <ScaleToFitIcon />
        </Button>
      </Tooltip>
      <ZoomControl buttonClass={styles.previewControlButton} />
    </Flex>
  );
};

import { useCallback, useMemo, useRef, useState } from 'react';
import ScaleToFitIcon from '@assets/icons/justify-stretch.svg?react';
import {
  ArrowLeftIcon,
  ArrowRightIcon,
  Cross2Icon,
  ExternalLinkIcon,
} from '@radix-ui/react-icons';
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
import {
  buildContentOnlyPreviewDocument,
  toPlainText,
} from '@/features/conflict/conflictComparison';
import ViewportSelector from '@/features/layout/preview/ViewportSelector';
import {
  scaleValues,
  selectEditorViewPortScale,
  selectViewportMinHeight,
  selectViewportWidth,
  setEditorFrameViewPort,
} from '@/features/ui/uiSlice';

import type React from 'react';

import styles from './ConflictResolutionPage.module.css';

const EMPTY_HTML = '<main></main>';
const PREVIEW_FIT_PADDING = 96;
const PREVIEW_DRAG_THRESHOLD = 3;

type ConflictVersion = 'published' | 'new';

export type ConflictVersionSelection = ConflictVersion | undefined;

type VisualScrollAreas = Record<ConflictVersion, HTMLDivElement | null>;

export interface ConflictResolutionViewProps {
  label: string;
  comparison: React.ReactNode;
  currentIndex: number;
  reviewIndex: number;
  reviewTotal: number;
  unresolvedTotal: number;
  isResolving?: boolean;
  canResolveConflict?: boolean;
  onPrevious: () => void;
  onNext: () => void;
  onResolveConflict: () => void;
  onClose: () => void;
}

export const ConflictResolutionView = ({
  label,
  comparison,
  currentIndex,
  reviewIndex,
  reviewTotal,
  unresolvedTotal,
  isResolving = false,
  canResolveConflict = false,
  onPrevious,
  onNext,
  onResolveConflict,
  onClose,
}: ConflictResolutionViewProps) => {
  return (
    <div className={styles.page} data-testid="conflict-resolution-page">
      <ConflictHeader label={label} onClose={onClose} />
      <div className={styles.content}>{comparison}</div>
      <ConflictFooter
        reviewIndex={reviewIndex}
        reviewTotal={reviewTotal}
        currentIndex={currentIndex}
        unresolvedTotal={unresolvedTotal}
        onPrevious={onPrevious}
        onNext={onNext}
        onResolveConflict={onResolveConflict}
        isResolving={isResolving}
        canResolveConflict={canResolveConflict}
      />
    </div>
  );
};

interface ConflictHeaderProps {
  label: string;
  onClose: () => void;
}

const ConflictHeader = ({ label, onClose }: ConflictHeaderProps) => {
  return (
    <div className={styles.header}>
      <Flex align="center" gap="1" minWidth="0">
        <Text size="1" color="blue">
          Canvas
        </Text>
        <Text size="1" color="gray">
          /
        </Text>
        <Text size="1">Conflict</Text>
        <Text size="1" color="gray">
          /
        </Text>
        <Text size="1" className={styles.breadcrumbLabel}>
          {label}
        </Text>
        <Badge color="gray" variant="soft" radius="small">
          Page
        </Badge>
      </Flex>
      <IconButton
        variant="ghost"
        color="gray"
        highContrast
        aria-label="Close"
        onClick={onClose}
      >
        <Cross2Icon />
      </IconButton>
    </div>
  );
};

export interface PageConflictVersion {
  html: string;
  props?: unknown;
  text?: string;
  updated?: string;
  loading?: boolean;
  changed?: boolean;
}

export interface PageConflictComparisonViewProps {
  entityId: string;
  entityType: string;
  publishedVersion: PageConflictVersion;
  newVersion: PageConflictVersion;
  selectedVersion?: ConflictVersionSelection;
  onSelectVersion?: (version: ConflictVersion) => void;
  onPublishedPreviewClick?: () => void;
  onNewPreviewClick?: () => void;
}

const stringifyComparisonProps = (value: unknown): string =>
  typeof value === 'string' ? value : JSON.stringify(value ?? {}, null, 2);

const buildConflictPreviewUrl = (
  entityType: string,
  entityId: string,
  published: boolean,
): string => {
  // Derive the SPA base path (e.g. '/canvas') from the current URL by
  // stripping everything from '/conflict/' onward.
  const basePath = window.location.pathname.split('/conflict/')[0];
  const versionParam = published ? '?version=published' : '';
  return `${window.location.origin}${basePath}/conflict-preview/${entityType}/${entityId}/full${versionParam}`;
};

export const PageConflictComparisonView = ({
  entityId,
  entityType,
  publishedVersion,
  newVersion,
  selectedVersion,
  onSelectVersion,
  onPublishedPreviewClick,
  onNewPreviewClick,
}: PageConflictComparisonViewProps) => {
  const handlePublishedPreview =
    onPublishedPreviewClick ??
    (() =>
      window.open(
        buildConflictPreviewUrl(entityType, entityId, true),
        '_blank',
      ));
  const handleNewPreview =
    onNewPreviewClick ??
    (() =>
      window.open(
        buildConflictPreviewUrl(entityType, entityId, false),
        '_blank',
      ));
  const [tab, setTab] = useState('visual');
  const publishedContentHtml = useMemo(
    () => buildContentOnlyPreviewDocument(publishedVersion.html || EMPTY_HTML),
    [publishedVersion.html],
  );
  const newContentHtml = useMemo(
    () => buildContentOnlyPreviewDocument(newVersion.html || EMPTY_HTML),
    [newVersion.html],
  );
  const publishedText = useMemo(
    () => publishedVersion.text ?? toPlainText(publishedVersion.html),
    [publishedVersion.html, publishedVersion.text],
  );
  const newText = useMemo(
    () => newVersion.text ?? toPlainText(newVersion.html),
    [newVersion.html, newVersion.text],
  );
  const publishedProps = useMemo(
    () => stringifyComparisonProps(publishedVersion.props),
    [publishedVersion.props],
  );
  const newProps = useMemo(
    () => stringifyComparisonProps(newVersion.props),
    [newVersion.props],
  );
  const visualScrollAreasRef = useRef<VisualScrollAreas>({
    published: null,
    new: null,
  });
  const ignoredScrollPositionsRef = useRef(
    new Map<HTMLDivElement, { scrollLeft: number; scrollTop: number }>(),
  );

  const handleVisualScrollAreaMount = useCallback(
    (version: ConflictVersion, node: HTMLDivElement | null) => {
      visualScrollAreasRef.current[version] = node;
    },
    [],
  );

  const handleVisualScroll = useCallback(
    (sourceVersion: ConflictVersion, sourceScrollArea: HTMLDivElement) => {
      const ignoredScrollPosition =
        ignoredScrollPositionsRef.current.get(sourceScrollArea);
      if (ignoredScrollPosition) {
        ignoredScrollPositionsRef.current.delete(sourceScrollArea);
        if (
          Math.abs(
            sourceScrollArea.scrollLeft - ignoredScrollPosition.scrollLeft,
          ) < 0.5 &&
          Math.abs(
            sourceScrollArea.scrollTop - ignoredScrollPosition.scrollTop,
          ) < 0.5
        ) {
          return;
        }
      }

      const targetVersion: ConflictVersion =
        sourceVersion === 'published' ? 'new' : 'published';
      const targetScrollArea = visualScrollAreasRef.current[targetVersion];
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

      ignoredScrollPositionsRef.current.set(targetScrollArea, {
        scrollLeft: nextLeft,
        scrollTop: nextTop,
      });
      targetScrollArea.scrollLeft = nextLeft;
      targetScrollArea.scrollTop = nextTop;
    },
    [],
  );

  return (
    <Tabs.Root value={tab} onValueChange={setTab} className={styles.tabRoot}>
      <Flex justify="between" align="center" className={styles.toolbar}>
        <Tabs.List size="1" className={styles.tabList}>
          <Tabs.Trigger className={styles.tabTrigger} value="visual">
            Visual
          </Tabs.Trigger>
          <Tabs.Trigger className={styles.tabTrigger} value="text">
            Text
          </Tabs.Trigger>
          <Tabs.Trigger className={styles.tabTrigger} value="props">
            Props
          </Tabs.Trigger>
        </Tabs.List>
        {tab === 'visual' && <ViewportSelector />}
      </Flex>
      <Tabs.Content value="visual" className={styles.tabContent}>
        <ComparisonGrid>
          <PreviewCard
            title="Published version"
            updated={publishedVersion.updated}
            version="published"
            selected={selectedVersion === 'published'}
            onSelect={onSelectVersion}
            onPreviewClick={handlePublishedPreview}
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
            title="New version"
            updated={newVersion.updated}
            changed={!!newVersion.changed}
            version="new"
            selected={selectedVersion === 'new'}
            onSelect={onSelectVersion}
            onPreviewClick={handleNewPreview}
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
      <Tabs.Content value="text" className={styles.tabContent}>
        <ComparisonGrid>
          <PreviewCard
            title="Published version"
            updated={publishedVersion.updated}
            version="published"
            selected={selectedVersion === 'published'}
            onSelect={onSelectVersion}
            onPreviewClick={handlePublishedPreview}
          >
            <TextPanel value={publishedText} />
          </PreviewCard>
          <PreviewCard
            title="New version"
            updated={newVersion.updated}
            changed={!!newVersion.changed}
            version="new"
            selected={selectedVersion === 'new'}
            onSelect={onSelectVersion}
            onPreviewClick={handleNewPreview}
          >
            <TextPanel value={newText} />
          </PreviewCard>
        </ComparisonGrid>
      </Tabs.Content>
      <Tabs.Content value="props" className={styles.tabContent}>
        <ComparisonGrid>
          <PreviewCard
            title="Published version"
            updated={publishedVersion.updated}
            version="published"
            selected={selectedVersion === 'published'}
            onSelect={onSelectVersion}
            onPreviewClick={handlePublishedPreview}
          >
            <JsonPanel value={publishedProps} />
          </PreviewCard>
          <PreviewCard
            title="New version"
            updated={newVersion.updated}
            changed={!!newVersion.changed}
            version="new"
            selected={selectedVersion === 'new'}
            onSelect={onSelectVersion}
            onPreviewClick={handleNewPreview}
          >
            <JsonPanel value={newProps} />
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
  version: ConflictVersion;
  selected?: boolean;
  changed?: boolean;
  onSelect?: (version: ConflictVersion) => void;
  onPreviewClick?: () => void;
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
  version: ConflictVersion;
  onScrollAreaMount?: (
    version: ConflictVersion,
    node: HTMLDivElement | null,
  ) => void;
  onScrollAreaScroll?: (
    version: ConflictVersion,
    scrollArea: HTMLDivElement,
  ) => void;
}) => {
  const previewScale = useAppSelector(selectEditorViewPortScale);
  const previewWidth = useAppSelector(selectViewportWidth);
  const previewMinHeight = useAppSelector(selectViewportMinHeight);
  const previewScrollAreaRef = useRef<HTMLDivElement | null>(null);
  const previewFrameWrapperRef = useRef<HTMLDivElement | null>(null);
  const dragStateRef = useRef<{
    pointerId: number;
    startX: number;
    startY: number;
    scrollLeft: number;
    scrollTop: number;
    didDrag: boolean;
  }>();
  const suppressNextClickRef = useRef(false);
  const [isDraggingPreview, setIsDraggingPreview] = useState(false);

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
        data-testid="canvas-conflict-preview-scroll-area"
        data-dragging={isDraggingPreview ? 'true' : undefined}
        onClick={handleClick}
        onPointerCancel={endDrag}
        onPointerDown={handlePointerDown}
        onPointerMove={handlePointerMove}
        onPointerUp={endDrag}
        onScroll={handleScroll}
      >
        {loading ? (
          <Spinner />
        ) : (
          <div
            ref={previewFrameWrapperRef}
            className={styles.previewFrameWrapper}
            style={
              {
                '--conflict-preview-scale': previewScale,
                ...(previewWidth ? { width: `${previewWidth}px` } : {}),
                ...(previewMinHeight
                  ? {
                      height: `${previewMinHeight}px`,
                      minHeight: `${previewMinHeight}px`,
                    }
                  : {}),
              } as React.CSSProperties
            }
          >
            <iframe
              title="Conflict preview"
              className={styles.previewFrame}
              srcDoc={html}
            />
          </div>
        )}
      </div>
      <ConflictPreviewControls
        previewScrollAreaRef={previewScrollAreaRef}
        previewFrameWrapperRef={previewFrameWrapperRef}
      />
    </div>
  );
};

const TextPanel = ({ value }: { value: string }) => (
  <pre className={styles.textPanel}>{value || 'No text content.'}</pre>
);

const JsonPanel = ({ value }: { value: string }) => (
  <pre className={styles.textPanel}>{value}</pre>
);

interface ConflictPreviewControlsProps {
  previewScrollAreaRef: React.RefObject<HTMLDivElement | null>;
  previewFrameWrapperRef: React.RefObject<HTMLDivElement | null>;
}

const ConflictPreviewControls = ({
  previewScrollAreaRef,
  previewFrameWrapperRef,
}: ConflictPreviewControlsProps) => {
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
      data-testid="canvas-conflict-preview-controls"
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
          data-testid="conflict-scale-to-fit"
        >
          <ScaleToFitIcon />
        </Button>
      </Tooltip>
      <ZoomControl buttonClass={styles.previewControlButton} />
    </Flex>
  );
};

interface ConflictFooterProps {
  currentIndex: number;
  reviewIndex: number;
  reviewTotal: number;
  unresolvedTotal: number;
  isResolving: boolean;
  canResolveConflict: boolean;
  onPrevious: () => void;
  onNext: () => void;
  onResolveConflict: () => void;
}

const ConflictFooter = ({
  currentIndex,
  reviewIndex,
  reviewTotal,
  unresolvedTotal,
  isResolving,
  canResolveConflict,
  onPrevious,
  onNext,
  onResolveConflict,
}: ConflictFooterProps) => (
  <div className={styles.footer}>
    <Text size="2" color="gray">
      Review {reviewIndex + 1} of {reviewTotal}
    </Text>
    <Flex align="center" gap="5">
      <Button
        variant="ghost"
        color="gray"
        disabled={currentIndex === 0 || isResolving}
        onClick={onPrevious}
      >
        <ArrowLeftIcon />
        Previous
      </Button>
      <Button
        variant="ghost"
        color="blue"
        disabled={currentIndex >= unresolvedTotal - 1 || isResolving}
        onClick={onNext}
      >
        Next
        <ArrowRightIcon />
      </Button>
      <Button
        onClick={onResolveConflict}
        disabled={isResolving || !canResolveConflict}
      >
        Resolve conflict
        <Spinner loading={isResolving} />
      </Button>
    </Flex>
  </div>
);

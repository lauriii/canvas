import { useEffect, useRef } from 'react';
import clsx from 'clsx';
import { formatDistanceToNow } from 'date-fns';
import {
  closestCenter,
  DndContext,
  KeyboardSensor,
  PointerSensor,
  useSensor,
  useSensors,
} from '@dnd-kit/core';
import {
  SortableContext,
  sortableKeyboardCoordinates,
  useSortable,
  verticalListSortingStrategy,
} from '@dnd-kit/sortable';
import { CSS } from '@dnd-kit/utilities';
import {
  CheckCircledIcon,
  CrossCircledIcon,
  DragHandleDots2Icon,
  ExclamationTriangleIcon,
  PlusIcon,
  TrashIcon,
} from '@radix-ui/react-icons';
import {
  Badge,
  Button,
  Flex,
  Link,
  Spinner,
  Text,
  Tooltip,
} from '@radix-ui/themes';

import { checkFrontendConnection } from './checkFrontendConnection';

import type { DragEndEvent } from '@dnd-kit/core';
import type { CacheInvalidationStatus } from '@/services/headlessFrontends';
import type { ConnectionStatus, HeadlessFrontend } from './types';

import styles from './HeadlessFrontends.module.css';

// How often a frontend in a failed state is re-checked.
const RETRY_INTERVAL_MS = 5000;

// Do not let an unresponsive frontend leave a row checking indefinitely.
const CONNECTION_CHECK_TIMEOUT_MS = 3000;

interface FrontendsListProps {
  frontends: HeadlessFrontend[];
  onAdd: () => void;
  onReorder: (oldIndex: number, newIndex: number) => void;
  onRemove: (id: string) => void;
  onStatusChange: (id: string, status: ConnectionStatus) => void;
  onRetryCacheInvalidation: (id: string) => void;
  // The frontend a retry is running for, if any.
  retryingCacheInvalidation: string | null;
  onOpenSetupGuide: () => void;
  disabled: boolean;
}

const FrontendsList = ({
  frontends,
  onAdd,
  onReorder,
  onRemove,
  onStatusChange,
  onRetryCacheInvalidation,
  retryingCacheInvalidation,
  onOpenSetupGuide,
  disabled,
}: FrontendsListProps) => {
  const sensors = useSensors(
    useSensor(PointerSensor),
    useSensor(KeyboardSensor, {
      coordinateGetter: sortableKeyboardCoordinates,
    }),
  );

  const handleDragEnd = (event: DragEndEvent) => {
    const { active, over } = event;
    if (over && active.id !== over.id) {
      const oldIndex = frontends.findIndex((item) => item.id === active.id);
      const newIndex = frontends.findIndex((item) => item.id === over.id);
      onReorder(oldIndex, newIndex);
    }
  };

  return (
    <DndContext
      sensors={sensors}
      collisionDetection={closestCenter}
      onDragEnd={handleDragEnd}
    >
      <SortableContext
        items={frontends.map((item) => item.id)}
        strategy={verticalListSortingStrategy}
      >
        <Flex direction="column" gap="2">
          {frontends.map((frontend, index) => (
            <FrontendRow
              key={frontend.id}
              frontend={frontend}
              onRemove={() => onRemove(frontend.id)}
              onStatusChange={(status) => onStatusChange(frontend.id, status)}
              onRetryCacheInvalidation={() =>
                onRetryCacheInvalidation(frontend.id)
              }
              retryingCacheInvalidation={retryingCacheInvalidation}
              onOpenSetupGuide={onOpenSetupGuide}
              disabled={disabled}
              data-testid={`canvas-headless-frontend-row-${index}`}
            />
          ))}
          <Button
            variant="ghost"
            color="gray"
            onClick={onAdd}
            disabled={disabled}
            className={styles.addRow}
            data-testid="canvas-headless-add-frontend"
          >
            <PlusIcon />
            Add frontend
          </Button>
        </Flex>
      </SortableContext>
    </DndContext>
  );
};

interface FrontendRowProps {
  frontend: HeadlessFrontend;
  onRemove: () => void;
  onStatusChange: (status: ConnectionStatus) => void;
  onRetryCacheInvalidation: () => void;
  retryingCacheInvalidation: string | null;
  onOpenSetupGuide: () => void;
  disabled: boolean;
  'data-testid'?: string;
}

const FrontendRow = ({
  frontend,
  onRemove,
  onStatusChange,
  onRetryCacheInvalidation,
  retryingCacheInvalidation,
  onOpenSetupGuide,
  disabled,
  'data-testid': dataTestId,
}: FrontendRowProps) => {
  const { cacheInvalidationStatus } = frontend;
  const {
    attributes,
    listeners,
    setNodeRef,
    transform,
    transition,
    isDragging,
  } = useSortable({ id: frontend.id, disabled });

  const onStatusChangeRef = useRef(onStatusChange);
  useEffect(() => {
    onStatusChangeRef.current = onStatusChange;
  }, [onStatusChange]);

  useEffect(() => {
    let cancelled = false;
    let retryTimer: ReturnType<typeof setTimeout> | undefined;
    let activeController: AbortController | undefined;

    const check = async () => {
      activeController = new AbortController();
      const timeout = setTimeout(
        () => activeController?.abort(),
        CONNECTION_CHECK_TIMEOUT_MS,
      );
      const status = await checkFrontendConnection(
        frontend.url,
        activeController.signal,
      );
      clearTimeout(timeout);

      if (cancelled) {
        return;
      }
      onStatusChangeRef.current(status);
      if (status !== 'ready') {
        // Failed frontends are re-checked silently: the badge stays in place
        // instead of flickering through the checking state.
        retryTimer = setTimeout(() => void check(), RETRY_INTERVAL_MS);
      }
    };

    void check();
    return () => {
      cancelled = true;
      activeController?.abort();
      clearTimeout(retryTimer);
    };
  }, [frontend.url]);

  const style = {
    transform: CSS.Transform.toString(transform),
    transition,
  };

  return (
    <Flex
      ref={setNodeRef}
      p="2"
      pr="3"
      gap="2"
      align="center"
      className={clsx(styles.frontendRow, {
        [styles.frontendRowDragging]: isDragging,
      })}
      style={style}
      data-testid={dataTestId}
    >
      <Button
        {...attributes}
        {...listeners}
        aria-label="Reorder frontend"
        disabled={disabled}
        variant="ghost"
        color="gray"
        className={clsx(styles.rowControl, styles.dragHandle)}
      >
        <DragHandleDots2Icon />
      </Button>
      <Text size="2" className={styles.frontendUrl}>
        {frontend.url}
      </Text>
      <Flex align="center" gap="3" ml="auto" flexShrink="0">
        {/* One status per row. A frontend the browser reaches, but that
            stopped receiving content updates, says so instead of "Ready". */}
        {cacheInvalidationStatus?.state === 'failing' &&
        frontend.status === 'ready' ? (
          <ContentUpdatesFailing
            status={cacheInvalidationStatus}
            onRetry={onRetryCacheInvalidation}
            isRetrying={retryingCacheInvalidation === frontend.id}
            disabled={retryingCacheInvalidation !== null}
          />
        ) : (
          <ConnectionStatusIndicator
            status={frontend.status}
            cacheInvalidationStatus={cacheInvalidationStatus}
            onOpenSetupGuide={onOpenSetupGuide}
          />
        )}
        <Button
          onClick={onRemove}
          aria-label="Remove frontend"
          disabled={disabled}
          variant="ghost"
          color="red"
          className={styles.rowControl}
        >
          <TrashIcon />
        </Button>
      </Flex>
    </Flex>
  );
};

// Rendered when its tooltip opens, so the time is current. Drupal's clock may
// run ahead of the browser's: never a time in the future.
const RelativeTime = ({ timestamp }: { timestamp: number }) =>
  formatDistanceToNow(new Date(Math.min(timestamp * 1000, Date.now())), {
    addSuffix: true,
  });

const endWithPeriod = (text: string) =>
  /[.!?]$/.test(text) ? text : `${text}.`;

// A frontend that used to read the cache tags Drupal announces, and no longer
// does: it may serve stale pages. The badge is the button that retries.
const ContentUpdatesFailing = ({
  status,
  onRetry,
  isRetrying,
  disabled,
}: {
  status: CacheInvalidationStatus;
  onRetry: () => void;
  isRetrying: boolean;
  disabled: boolean;
}) => (
  <Tooltip
    content={
      <>
        This frontend stopped receiving content updates, so it may show outdated
        pages. Canvas keeps trying, and tells it to expire its cached pages once
        it responds. Click to try now.
        {status.lastFailure !== null && (
          <>
            {' '}
            Last failure: <RelativeTime timestamp={status.lastFailure} />.
          </>
        )}
        {status.lastError !== null && ` ${endWithPeriod(status.lastError)}`}
      </>
    }
  >
    <Button
      size="1"
      color="amber"
      variant="soft"
      onClick={onRetry}
      loading={isRetrying}
      disabled={disabled}
    >
      <ExclamationTriangleIcon width="12" height="12" />
      Not receiving content updates
    </Button>
  </Tooltip>
);

const ConnectionStatusIndicator = ({
  status,
  cacheInvalidationStatus,
  onOpenSetupGuide,
}: {
  status: ConnectionStatus;
  cacheInvalidationStatus: CacheInvalidationStatus | null;
  onOpenSetupGuide: () => void;
}) => {
  if (status === 'checking') {
    return (
      <Flex gap="1" align="center">
        <Spinner size="1" />
        <Text size="1" color="gray">
          Checking…
        </Text>
      </Flex>
    );
  }
  if (status === 'unreachable') {
    return (
      <Tooltip content="The URL did not respond. Check that the site is deployed and the URL is correct.">
        <Badge size="1" color="red" variant="soft">
          <CrossCircledIcon width="12" height="12" />
          Unreachable
        </Badge>
      </Tooltip>
    );
  }
  if (status === 'setup-needed') {
    return (
      <Tooltip
        aria-label="The site responded, but no Canvas adapter was found. Install the adapter for your framework — see the setup guide."
        content={
          <>
            The site responded, but no Canvas adapter was found. Install the
            adapter for your framework — see the{' '}
            <Link
              href="#canvas-headless-setup-guide"
              className={styles.setupGuideTooltipLink}
              onClick={(event) => {
                event.preventDefault();
                onOpenSetupGuide();
              }}
            >
              setup guide
            </Link>
            .
          </>
        }
      >
        <Badge size="1" color="amber" variant="soft">
          <ExclamationTriangleIcon width="12" height="12" />
          Setup needed
        </Badge>
      </Tooltip>
    );
  }
  if (!cacheInvalidationStatus) {
    return (
      <Badge size="1" color="green" variant="soft">
        <CheckCircledIcon width="12" height="12" />
        Ready
      </Badge>
    );
  }
  // Confirms to whoever set up page caching that it is connected. Focusable,
  // so that the tooltip also opens from the keyboard.
  return (
    <Tooltip
      content={
        <>
          Cached pages expire when content changes. Last update received{' '}
          <RelativeTime timestamp={cacheInvalidationStatus.lastSuccess} />.
        </>
      }
    >
      <Badge size="1" color="green" variant="soft" tabIndex={0}>
        <CheckCircledIcon width="12" height="12" />
        Ready
      </Badge>
    </Tooltip>
  );
};

export default FrontendsList;

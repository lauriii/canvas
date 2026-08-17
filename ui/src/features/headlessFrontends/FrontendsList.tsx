import { useEffect, useRef } from 'react';
import clsx from 'clsx';
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
  onOpenSetupGuide: () => void;
  disabled: boolean;
}

const FrontendsList = ({
  frontends,
  onAdd,
  onReorder,
  onRemove,
  onStatusChange,
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
            {Drupal.t('Add frontend')}
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
  onOpenSetupGuide: () => void;
  disabled: boolean;
  'data-testid'?: string;
}

const FrontendRow = ({
  frontend,
  onRemove,
  onStatusChange,
  onOpenSetupGuide,
  disabled,
  'data-testid': dataTestId,
}: FrontendRowProps) => {
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
        aria-label={Drupal.t('Reorder frontend')}
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
        <ConnectionStatusIndicator
          status={frontend.status}
          onOpenSetupGuide={onOpenSetupGuide}
        />
        <Button
          onClick={onRemove}
          aria-label={Drupal.t('Remove frontend')}
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

const ConnectionStatusIndicator = ({
  status,
  onOpenSetupGuide,
}: {
  status: ConnectionStatus;
  onOpenSetupGuide: () => void;
}) => {
  if (status === 'checking') {
    return (
      <Flex gap="1" align="center">
        <Spinner size="1" />
        <Text size="1" color="gray">
          {Drupal.t('Checking…')}
        </Text>
      </Flex>
    );
  }
  if (status === 'unreachable') {
    return (
      <Tooltip
        content={Drupal.t(
          'The URL did not respond. Check that the site is deployed and the URL is correct.',
        )}
      >
        <Badge size="1" color="red" variant="soft">
          <CrossCircledIcon width="12" height="12" />
          {Drupal.t('Unreachable')}
        </Badge>
      </Tooltip>
    );
  }
  if (status === 'setup-needed') {
    return (
      <Tooltip
        aria-label={Drupal.t(
          'The site responded, but no Canvas adapter was found. Install the adapter for your framework — see the setup guide.',
        )}
        content={
          <>
            {Drupal.t(
              'The site responded, but no Canvas adapter was found. Install the adapter for your framework — see the',
            )}{' '}
            <Link
              href="#canvas-headless-setup-guide"
              className={styles.setupGuideTooltipLink}
              onClick={(event) => {
                event.preventDefault();
                onOpenSetupGuide();
              }}
            >
              {Drupal.t('setup guide')}
            </Link>
            .
          </>
        }
      >
        <Badge size="1" color="amber" variant="soft">
          <ExclamationTriangleIcon width="12" height="12" />
          {Drupal.t('Setup needed')}
        </Badge>
      </Tooltip>
    );
  }
  return (
    <Badge size="1" color="green" variant="soft">
      <CheckCircledIcon width="12" height="12" />
      {Drupal.t('Ready')}
    </Badge>
  );
};

export default FrontendsList;

import { useEffect, useMemo, useRef, useState } from 'react';
import {
  ChevronDownIcon,
  ChevronUpIcon,
  Cross2Icon,
  ImageIcon,
  PlusIcon,
} from '@radix-ui/react-icons';
import { Button, Flex, IconButton, Text } from '@radix-ui/themes';

import MediaBrowser from './MediaBrowser';
import { browseMedia, getMediaTypeFromContext } from './mediaEndpoints';

import type {
  ClientWidgetContext,
  ClientWidgetDefinition,
  ClientWidgetProps,
} from '../types';
import type { MediaSelectionItem } from './MediaBrowser';
import type { MediaBrowseItem } from './mediaEndpoints';

const isMultiple = (context: ClientWidgetContext): boolean =>
  context.cardinality !== 1;

/**
 * Normalizes a stored media source value to a list of target ids. The stored
 * value is a bare id for single cardinality, an array of ids for multiple;
 * `{target_id}` records are tolerated for robustness against pre-transform
 * shapes.
 */
const extractTargetIds = (value: unknown): Array<number | string> => {
  if (value === null || value === undefined || value === '') {
    return [];
  }
  const entries = Array.isArray(value) ? value : [value];
  const ids: Array<number | string> = [];
  entries.forEach((entry) => {
    if (entry === null || entry === undefined || entry === '') {
      return;
    }
    if (typeof entry === 'number' || typeof entry === 'string') {
      ids.push(entry);
      return;
    }
    if (typeof entry === 'object' && 'target_id' in entry) {
      const targetId = (entry as { target_id: unknown }).target_id;
      if (
        typeof targetId === 'number' ||
        (typeof targetId === 'string' && targetId !== '')
      ) {
        ids.push(targetId);
      }
    }
  });
  return ids;
};

const thumbStyle: React.CSSProperties = {
  width: '40px',
  height: '40px',
  objectFit: 'cover',
  borderRadius: 'var(--radius-2)',
  background: 'var(--gray-3)',
  color: 'var(--gray-8)',
  flexShrink: 0,
};

// Card row for one selected media item.
const selectionRowStyle: React.CSSProperties = {
  border: '1px solid var(--gray-5)',
  borderRadius: 'var(--radius-3)',
  padding: 'var(--space-1)',
  paddingRight: 'var(--space-2)',
  background: 'var(--color-panel-solid)',
};

const labelStyle: React.CSSProperties = {
  flexGrow: 1,
  overflow: 'hidden',
  textOverflow: 'ellipsis',
  whiteSpace: 'nowrap',
};

/**
 * Native counterpart of `media_library_widget`.
 *
 * Closed state: the current selection (thumbnail, label, reorder and remove
 * buttons per item) plus an "Add media" button that opens the browse dialog.
 * The selection is stored as target ids; labels and thumbnails for ids
 * restored from the model are hydrated from the browse endpoint on mount.
 */
const MediaLibraryWidget = (props: ClientWidgetProps) => {
  const { value, onChange, disabled, inputId, cardinality } = props;
  const mediaType = getMediaTypeFromContext(props);
  const [open, setOpen] = useState(false);
  const [hydrated, setHydrated] = useState<Record<string, MediaBrowseItem>>({});
  // Ids already requested from the hydration endpoint; prevents refetch loops
  // when the server does not return an id (e.g. deleted media).
  const requestedIds = useRef<Set<string>>(new Set());

  const items = useMemo(
    () => (Array.isArray(value) ? (value as MediaSelectionItem[]) : []),
    [value],
  );

  // `fromModel` is synchronous and only knows target ids; fetch the labels
  // and thumbnails for any un-hydrated items after mount.
  useEffect(() => {
    if (!mediaType) {
      return;
    }
    const missing = items.filter(
      (item) => item.label === '' && !requestedIds.current.has(String(item.id)),
    );
    if (missing.length === 0) {
      return;
    }
    missing.forEach((item) => requestedIds.current.add(String(item.id)));
    let cancelled = false;
    browseMedia(mediaType, { ids: missing.map((item) => item.id) })
      .then((response) => {
        if (cancelled) {
          return;
        }
        setHydrated((current) => {
          const next = { ...current };
          response.items.forEach((item) => {
            next[String(item.id)] = item;
          });
          return next;
        });
      })
      .catch(() => {
        // Hydration is presentational; un-hydrated items still render by id.
      });
    return () => {
      cancelled = true;
    };
  }, [items, mediaType]);

  // The selection with hydrated presentation data folded in. Interactions
  // emit these merged items so `toModel` sees `inputsResolved` where known.
  const displayItems: MediaSelectionItem[] = items.map((item) => {
    const hydratedItem = hydrated[String(item.id)];
    if (!hydratedItem) {
      return item;
    }
    return {
      id: item.id,
      label: item.label || hydratedItem.label,
      thumbnailUrl: item.thumbnailUrl ?? hydratedItem.thumbnailUrl,
      inputsResolved: item.inputsResolved ?? hydratedItem.inputs_resolved,
    };
  });

  const multiple = isMultiple(props);
  const full = cardinality !== -1 && displayItems.length >= cardinality;

  const removeItem = (index: number) => {
    onChange(displayItems.filter((_, itemIndex) => itemIndex !== index));
  };

  const moveItem = (index: number, delta: number) => {
    const target = index + delta;
    if (target < 0 || target >= displayItems.length) {
      return;
    }
    const next = [...displayItems];
    [next[index], next[target]] = [next[target], next[index]];
    onChange(next);
  };

  return (
    <Flex direction="column" gap="2" align="start">
      {displayItems.map((item, index) => {
        const label = item.label || `Media ${item.id}`;
        return (
          <Flex
            key={`${item.id}-${index}`}
            align="center"
            gap="2"
            width="100%"
            style={selectionRowStyle}
            data-testid="canvas-media-selection-item"
          >
            {item.thumbnailUrl ? (
              <img src={item.thumbnailUrl} alt="" style={thumbStyle} />
            ) : (
              <Flex align="center" justify="center" style={thumbStyle}>
                <ImageIcon />
              </Flex>
            )}
            <Text size="1" style={labelStyle} title={label}>
              {label}
            </Text>
            {multiple && displayItems.length > 1 && (
              <>
                <IconButton
                  size="1"
                  variant="ghost"
                  color="gray"
                  aria-label={`Move ${label} up`}
                  disabled={disabled || index === 0}
                  onClick={() => moveItem(index, -1)}
                >
                  <ChevronUpIcon />
                </IconButton>
                <IconButton
                  size="1"
                  variant="ghost"
                  color="gray"
                  aria-label={`Move ${label} down`}
                  disabled={disabled || index === displayItems.length - 1}
                  onClick={() => moveItem(index, 1)}
                >
                  <ChevronDownIcon />
                </IconButton>
              </>
            )}
            <IconButton
              size="1"
              variant="ghost"
              color="gray"
              aria-label={`Remove ${label}`}
              disabled={disabled}
              onClick={() => removeItem(index)}
            >
              <Cross2Icon />
            </IconButton>
          </Flex>
        );
      })}
      {!full && (
        <Button
          id={inputId}
          size="1"
          variant="soft"
          disabled={disabled || !mediaType}
          onClick={() => setOpen(true)}
        >
          <PlusIcon /> Add media
        </Button>
      )}
      {mediaType && (
        <MediaBrowser
          open={open}
          onOpenChange={setOpen}
          mediaType={mediaType}
          initialSelection={displayItems}
          maxItems={cardinality === -1 ? null : cardinality}
          onInsert={onChange}
        />
      )}
    </Flex>
  );
};

export const mediaLibraryWidget: ClientWidgetDefinition = {
  component: MediaLibraryWidget,
  // A media prop without a resolvable media type cannot browse or upload;
  // send it to the escape hatch.
  isEligible: (context) => getMediaTypeFromContext(context) !== null,
  codec: {
    // Parity with the server-widget path's `mediaSelection` +
    // `mainProperty(target_id)` transforms: the stored source value is the
    // media target id (an array of ids for multi-cardinality props).
    toModel(widgetValue, context) {
      const selection = Array.isArray(widgetValue)
        ? (widgetValue as MediaSelectionItem[])
        : [];
      if (selection.length === 0) {
        return null;
      }
      // Optimistic resolved value: image-backed media carries
      // `inputs_resolved` matching the image prop schema; otherwise fall back
      // to the target id and let the authoritative server evaluation echo
      // supply the real resolved value.
      const resolvedFor = (item: MediaSelectionItem) =>
        item.inputsResolved ?? item.id;
      if (isMultiple(context)) {
        return {
          resolved: selection.map(resolvedFor),
          source: selection.map((item) => item.id),
        };
      }
      return {
        resolved: resolvedFor(selection[0]),
        source: selection[0].id,
      };
    },
    fromModel(sourceValue) {
      // Only ids are known synchronously; the widget hydrates labels and
      // thumbnails from the browse endpoint after mount.
      return extractTargetIds(sourceValue).map(
        (id): MediaSelectionItem => ({
          id,
          label: '',
          thumbnailUrl: null,
          inputsResolved: null,
        }),
      );
    },
  },
  handlesMultipleValues: true,
};

export default MediaLibraryWidget;

import { useEffect, useState } from 'react';
import {
  Select,
  SelectContent,
  SelectGroup,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@wb/client/components/ui/select';
import { fetchPreviewEntitySuggestions } from '@wb/lib/preview-entity-suggestions';
import { cn } from '@wb/lib/utils';

import type { PreviewEntitySuggestion } from '@wb/lib/preview-entity-suggestions';

interface PreviewEntityPickerProps {
  entityTypeId: string;
  bundle: string;
  siteUrl: string | null;
  selectedEntityId: string | null;
  onSelect: (id: string | null) => void;
  onError?: (message: string) => void;
  idSuffix?: string;
  label?: string;
  layout?: 'full' | 'compact';
  allowEmptySelection?: boolean;
}

const EMPTY_SELECTION_VALUE = '__canvas_no_preview_entity__';
const EMPTY_SELECTION_LABEL = '- Select preview entity -';

type LoadState =
  | { status: 'idle' }
  | { status: 'loading' }
  | { status: 'error'; message: string }
  | { status: 'ready'; entities: PreviewEntitySuggestion[] };

export function PreviewEntityPicker({
  entityTypeId,
  bundle,
  siteUrl,
  selectedEntityId,
  onSelect,
  onError,
  idSuffix = 'default',
  label = 'Preview content:',
  layout = 'full',
  allowEmptySelection = false,
}: PreviewEntityPickerProps) {
  const [loadState, setLoadState] = useState<LoadState>({ status: 'idle' });
  const [retryCounter, setRetryCounter] = useState(0);
  const [hasAppliedDefaultSelection, setHasAppliedDefaultSelection] =
    useState(false);

  useEffect(() => {
    setHasAppliedDefaultSelection(false);
  }, [siteUrl, entityTypeId, bundle, idSuffix]);

  useEffect(() => {
    if (!siteUrl) {
      setLoadState({ status: 'idle' });
      return;
    }

    const abortController = new AbortController();
    setLoadState({ status: 'loading' });

    void (async () => {
      try {
        const entities = await fetchPreviewEntitySuggestions(
          entityTypeId,
          bundle,
          abortController.signal,
        );
        if (abortController.signal.aborted) {
          return;
        }
        setLoadState({ status: 'ready', entities });
      } catch (error) {
        if (abortController.signal.aborted) {
          return;
        }
        if (error instanceof DOMException && error.name === 'AbortError') {
          return;
        }
        const message =
          error instanceof Error
            ? error.message
            : 'Failed to load preview entity suggestions.';
        setLoadState({ status: 'error', message });
        onError?.(message);
      }
    })();

    return () => {
      abortController.abort();
    };
  }, [siteUrl, entityTypeId, bundle, retryCounter, onError]);

  // Auto-select the first available entity once the list is loaded, and reset
  // the selection if the previously selected entity is no longer in the list
  // (e.g. after an entity-type/bundle change).
  useEffect(() => {
    if (loadState.status !== 'ready' || loadState.entities.length === 0) {
      return;
    }
    const stillAvailable =
      selectedEntityId !== null &&
      loadState.entities.some((entity) => entity.id === selectedEntityId);
    if (stillAvailable) {
      return;
    }
    if (
      allowEmptySelection &&
      selectedEntityId === null &&
      hasAppliedDefaultSelection
    ) {
      return;
    }
    setHasAppliedDefaultSelection(true);
    onSelect(loadState.entities[0].id);
  }, [
    allowEmptySelection,
    hasAppliedDefaultSelection,
    loadState,
    selectedEntityId,
    onSelect,
  ]);

  if (!siteUrl) {
    return (
      <div className="flex flex-col gap-1 border p-3 text-xs text-muted-foreground">
        <span className="font-semibold text-foreground">No data source.</span>
        <span>
          Set the <code className="font-mono">CANVAS_SITE_URL</code> env var (in{' '}
          <code className="font-mono">.env</code>) to render this preview
          against entities from a live Drupal site.
        </span>
      </div>
    );
  }

  if (loadState.status === 'loading' || loadState.status === 'idle') {
    return (
      <div className="border p-3 text-xs text-muted-foreground">
        Loading {entityTypeId} ({bundle}) entities…
      </div>
    );
  }

  if (loadState.status === 'error') {
    return (
      <div className="flex flex-col gap-2 border p-3 text-xs">
        <span className="font-semibold text-destructive">
          Failed to load entities
        </span>
        <span className="text-muted-foreground">{loadState.message}</span>
        <button
          type="button"
          className="self-start border px-2 py-1 text-xs hover:bg-accent"
          onClick={() => setRetryCounter((value) => value + 1)}
        >
          Retry
        </button>
      </div>
    );
  }

  if (loadState.entities.length === 0) {
    return (
      <div className="border p-3 text-xs text-muted-foreground">
        No {entityTypeId} ({bundle}) entities found on the target Drupal site.
      </div>
    );
  }

  const selectedEntity = loadState.entities.find(
    (entity) => entity.id === selectedEntityId,
  );
  const selectedValue =
    selectedEntity?.id ??
    (allowEmptySelection ? EMPTY_SELECTION_VALUE : loadState.entities[0].id);
  const selectItems = [
    ...(allowEmptySelection
      ? [{ label: EMPTY_SELECTION_LABEL, value: EMPTY_SELECTION_VALUE }]
      : []),
    ...loadState.entities.map((entity) => ({
      label: entity.label,
      value: entity.id,
    })),
  ];
  const selectId = `canvas-preview-entity-picker-${idSuffix.replace(/[^a-zA-Z0-9_-]/g, '-')}`;

  return (
    <div
      className={cn(
        'flex items-center gap-2 border p-2 text-xs',
        layout === 'compact' && 'w-[min(100%,24rem)] shrink-0 border-0 p-0',
      )}
    >
      <label
        htmlFor={selectId}
        className={cn(
          'text-muted-foreground',
          layout === 'compact' && 'shrink-0',
        )}
      >
        {label}
      </label>
      <Select
        items={selectItems}
        value={selectedValue}
        onValueChange={(id) => {
          onSelect(id === EMPTY_SELECTION_VALUE ? null : id);
        }}
      >
        <SelectTrigger
          id={selectId}
          className={cn(layout === 'compact' && 'min-w-0 flex-1')}
        >
          <SelectValue placeholder="Select preview content" />
        </SelectTrigger>
        <SelectContent>
          <SelectGroup>
            {allowEmptySelection ? (
              <SelectItem value={EMPTY_SELECTION_VALUE}>
                {EMPTY_SELECTION_LABEL}
              </SelectItem>
            ) : null}
            {loadState.entities.map((entity: PreviewEntitySuggestion) => (
              <SelectItem key={entity.id} value={entity.id}>
                {entity.label}
              </SelectItem>
            ))}
          </SelectGroup>
        </SelectContent>
      </Select>
    </div>
  );
}

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

import type { PreviewEntitySuggestion } from '@wb/lib/preview-entity-suggestions';

interface ContentTemplateEntityPickerProps {
  entityTypeId: string;
  bundle: string;
  siteUrl: string | null;
  selectedEntityId: string | null;
  onSelect: (id: string | null) => void;
  onError?: (message: string) => void;
}

type LoadState =
  | { status: 'idle' }
  | { status: 'loading' }
  | { status: 'error'; message: string }
  | { status: 'ready'; entities: PreviewEntitySuggestion[] };

export function ContentTemplateEntityPicker({
  entityTypeId,
  bundle,
  siteUrl,
  selectedEntityId,
  onSelect,
  onError,
}: ContentTemplateEntityPickerProps) {
  const [loadState, setLoadState] = useState<LoadState>({ status: 'idle' });
  const [retryCounter, setRetryCounter] = useState(0);

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
    if (!stillAvailable) {
      onSelect(loadState.entities[0].id);
    }
  }, [loadState, selectedEntityId, onSelect]);

  if (!siteUrl) {
    return (
      <div className="flex flex-col gap-1 border p-3 text-xs text-muted-foreground">
        <span className="font-semibold text-foreground">No data source.</span>
        <span>
          Set the <code className="font-mono">CANVAS_SITE_URL</code> env var (in{' '}
          <code className="font-mono">.env</code>) to preview this template
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

  const selectedEntity =
    loadState.entities.find((entity) => entity.id === selectedEntityId) ??
    loadState.entities[0];
  const selectItems = loadState.entities.map((entity) => ({
    label: entity.label,
    value: entity.id,
  }));

  return (
    <div className="flex items-center gap-2 border p-2 text-xs">
      <label
        htmlFor="canvas-content-template-entity-picker"
        className="text-muted-foreground"
      >
        Preview content:
      </label>
      <Select
        items={selectItems}
        value={selectedEntity.id}
        onValueChange={(id) => {
          onSelect(id);
        }}
      >
        <SelectTrigger id="canvas-content-template-entity-picker">
          <SelectValue placeholder="Select preview content" />
        </SelectTrigger>
        <SelectContent>
          <SelectGroup>
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

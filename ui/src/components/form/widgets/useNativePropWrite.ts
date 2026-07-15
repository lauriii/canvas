import { useCallback, useEffect, useMemo, useRef } from 'react';

import { useAppDispatch } from '@/app/hooks';
import { DEBOUNCE_TIMEOUT } from '@/components/form/react-hook-form/fields/componentFormData';
import { POLLED_BACKGROUND_TIMEOUT } from '@/components/form/react-hook-form/fields/componentFormHandlers';
import { ComponentPreviewUpdateEvent } from '@/components/form/react-hook-form/fields/componentPreviewEvents';
import { isEvaluatedComponentModel } from '@/features/layout/layoutModelSlice';
import { setPreviewBackgroundUpdate } from '@/features/pagePreview/previewSlice';
import useInputUIData from '@/hooks/useInputUIData';
import { usePatchComponent } from '@/services/preview';
import { isPropSourceComponent } from '@/types/Component';

import type {
  Sources,
  StaticPropSource,
} from '@/features/layout/layoutModelSlice';
import type { ClientWidgetContext, WidgetCodecResult } from './types';

// In-flight edits per component instance, keyed by prop. The Redux model only
// reflects an edit once the PATCH response lands (pessimistic update), so a
// quick edit to a second prop would otherwise send a model missing the first
// prop's still-in-flight change. The server-form path solves this with the
// form state slice; the native path merges pending codec results instead.
// Entries become no-ops once the store catches up, and the whole component
// entry is cleared on selection change and undo/redo (see clearPendingWrites).
const pendingWrites = new Map<string, Map<string, WidgetCodecResult>>();

export function clearPendingWrites(componentUuid: string): void {
  pendingWrites.delete(componentUuid);
}

/**
 * The model write path for native client widgets.
 *
 * Feeds the same pipeline as the server-rendered widgets do today — resolved
 * update, source sync, client-side preview update event, debounced auto-save
 * patch — but from codec output instead of transformed Drupal form state. No
 * transforms are involved on this path.
 */
export function useNativePropWrite(context: ClientWidgetContext) {
  const dispatch = useAppDispatch();
  const inputAndUiData = useInputUIData();
  const patchComponent = usePatchComponent();
  const { propName } = context;
  const { selectedComponent, selectedComponentType, components, model } =
    inputAndUiData;

  const component = components?.[selectedComponentType];
  const isScalarProp = ['number', 'integer', 'string', 'boolean'].includes(
    context.jsonSchema.type,
  );

  // Keep the latest inputs in a ref so the debounced writer never closes over
  // stale model state.
  const latest = useRef({ inputAndUiData, component, model });
  latest.current = { inputAndUiData, component, model };

  const debounceTimer = useRef<ReturnType<typeof setTimeout> | null>(null);
  const backgroundTimer = useRef<ReturnType<typeof setTimeout> | null>(null);
  useEffect(
    () => () => {
      if (debounceTimer.current) {
        clearTimeout(debounceTimer.current);
      }
      if (backgroundTimer.current) {
        clearTimeout(backgroundTimer.current);
      }
    },
    [],
  );

  const writeNow = useCallback(
    (codecResult: WidgetCodecResult) => {
      const {
        inputAndUiData: uiData,
        component: currentComponent,
        model: currentModel,
      } = latest.current;
      const selectedModel = currentModel?.[selectedComponent];
      if (!selectedModel || !currentComponent) {
        return;
      }

      const componentPending =
        pendingWrites.get(selectedComponent) ??
        new Map<string, WidgetCodecResult>();
      componentPending.set(propName, codecResult);
      pendingWrites.set(selectedComponent, componentPending);

      const resolved = { ...selectedModel.resolved };
      componentPending.forEach((pendingResult, pendingProp) => {
        if (pendingResult === null) {
          delete resolved[pendingProp];
        } else {
          resolved[pendingProp] =
            pendingResult.resolved as (typeof resolved)[string];
        }
      });

      let backgroundPreviewUpdate = false;
      if (isScalarProp) {
        // Fire an event to allow listeners to attempt real-time updates.
        const previewUpdateEvent = new ComponentPreviewUpdateEvent(
          selectedComponent,
          propName,
          resolved[propName],
        );
        document.dispatchEvent(previewUpdateEvent);
        dispatch(
          setPreviewBackgroundUpdate(
            previewUpdateEvent.getPreviewBackgroundUpdate(),
          ),
        );
        backgroundPreviewUpdate =
          previewUpdateEvent.getPreviewBackgroundUpdate();
      }

      const updateBackend = () => {
        if (
          isEvaluatedComponentModel(selectedModel) &&
          isPropSourceComponent(currentComponent)
        ) {
          // Update source entries for PENDING props only. Untouched props
          // keep their stored source entries verbatim: after a server
          // evaluation echo their resolved values are evaluated objects
          // (image URLs, entity data), and syncing those into `source` would
          // corrupt stored reference ids.
          const source: Sources = { ...selectedModel.source };
          componentPending.forEach((pendingResult, pendingProp) => {
            if (pendingResult === null) {
              // An empty value removes the prop's source entry so the server
              // does not evaluate it, matching syncPropSourcesToResolvedValues.
              delete source[pendingProp];
              return;
            }
            // A prop edited from empty has no source entry yet; seed it from
            // the prop's metadata like the server-form path does.
            const existingSource =
              source[pendingProp] ??
              (currentComponent.propSources[
                pendingProp
              ] as unknown as Sources[string]);
            source[pendingProp] = {
              ...existingSource,
              // Widgets whose stored source value differs from the resolved
              // value (media/entity references store target ids while
              // resolved carries the evaluated object) provide it explicitly.
              value: (pendingResult.source ??
                pendingResult.resolved) as StaticPropSource['value'],
            } as Sources[string];
          });
          patchComponent(uiData, { source, resolved });
          return;
        }
        patchComponent(uiData, { ...selectedModel, resolved });
      };

      if (backgroundPreviewUpdate) {
        // The preview already updated client-side; further debounce the
        // backend patch so a typing burst produces one request.
        if (backgroundTimer.current) {
          clearTimeout(backgroundTimer.current);
        }
        backgroundTimer.current = setTimeout(
          updateBackend,
          POLLED_BACKGROUND_TIMEOUT,
        );
        return;
      }
      updateBackend();
    },
    [dispatch, isScalarProp, patchComponent, propName, selectedComponent],
  );

  const write = useCallback(
    (codecResult: WidgetCodecResult, options?: { immediate?: boolean }) => {
      if (debounceTimer.current) {
        clearTimeout(debounceTimer.current);
        debounceTimer.current = null;
      }
      if (options?.immediate) {
        writeNow(codecResult);
        return;
      }
      debounceTimer.current = setTimeout(
        () => writeNow(codecResult),
        DEBOUNCE_TIMEOUT,
      );
    },
    [writeNow],
  );

  return useMemo(
    () => ({ write, inputAndUiData, component }),
    [write, inputAndUiData, component],
  );
}

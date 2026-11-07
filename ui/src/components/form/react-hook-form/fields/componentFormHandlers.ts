import {
  isEvaluatedComponentModel,
  syncPropSourcesToResolvedValues,
} from '@/features/layout/layoutModelSlice';
import { setPreviewBackgroundUpdate } from '@/features/pagePreview/previewSlice';
import { extractEntityParams } from '@/services/baseQuery';

import { DEBOUNCE_TIMEOUT, getPropsValues } from './componentFormData';
import { ComponentPreviewUpdateEvent } from './componentPreviewEvents';

import type { PropsValues } from '@drupal-canvas/types';
import type { Dispatch } from '@reduxjs/toolkit';
import type { InputUIData } from '@/types/Form';

export const POLLED_BACKGROUND_TIMEOUT = 1000;

/**
 * A PATCH waiting to be sent, and the editor URL it was made on.
 */
export interface PendingBackendUpdate {
  timer: ReturnType<typeof setTimeout>;
  send: () => void;
  editorUrl: string;
}

const scheduleBackendUpdate = (
  pending: React.MutableRefObject<PendingBackendUpdate | null>,
  send: () => void,
  delay: number,
) => {
  if (pending.current) {
    clearTimeout(pending.current.timer);
  }
  pending.current = {
    timer: setTimeout(() => {
      pending.current = null;
      send();
    }, delay),
    send,
    editorUrl: window.location.href,
  };
};

/**
 * Sends a waiting PATCH right away, for example when its field goes away.
 *
 * Drops it if the editor moved to another entity, whose URL the PATCH would
 * resolve to, or if `isCurrent()` says the change no longer applies.
 */
export const flushBackendUpdate = (
  pending: React.MutableRefObject<PendingBackendUpdate | null>,
  isCurrent: () => boolean = () => true,
) => {
  const update = pending.current;
  if (!update) {
    return;
  }
  clearTimeout(update.timer);
  pending.current = null;
  if (
    JSON.stringify(extractEntityParams(update.editorUrl)) ===
      JSON.stringify(extractEntityParams(window.location.href)) &&
    isCurrent()
  ) {
    update.send();
  }
};

/**
 * Dependencies needed for component form state handling.
 * Provided by useComponentFormInputInfo and the component instance form.
 */
interface ComponentFormHandlerDependencies {
  dispatch: Dispatch;
  transforms: any;
  selectedComponentType: string;
  selectedComponent: string;
  propName: string;
  isScalarProp: boolean;
  polledBackgroundUpdate: React.MutableRefObject<PendingBackendUpdate | null>;
  component: any;
  patchComponent: (inputUiData: InputUIData, params: any) => void;
  editorFrameContext: string;
  version: string;
}

/**
 * Creates a form state handler for component instance forms.
 *
 * The returned function is called on every form state change and is responsible
 * for the full update pipeline:
 *   1. Convert form state → resolved prop values (via getPropsValues)
 *   2. Fire a client-side preview update event for scalar props
 *   3. Sync prop sources and PATCH the component to the backend
 */
export const createComponentFormStateHandler = (
  deps: ComponentFormHandlerDependencies,
) => {
  return (
    newFormState: PropsValues,
    newInputAndUiData: InputUIData,
    currFormState: PropsValues,
  ) => {
    // Apply (client-side) transforms for form state.
    const { propsValues: values, selectedModel } = getPropsValues(
      { ...currFormState, ...newFormState },
      newInputAndUiData,
      // If transforms are not available (typically because TransformsContext is
      // not available to the input), fall back to the transforms stored in the
      // global window object.
      Object.keys(deps.transforms || {}).length > 0
        ? deps.transforms
        : (window as any)._canvasTransforms[deps.selectedComponentType],
    );

    const resolved = { ...selectedModel.resolved, ...values };

    let backgroundPreviewUpdate = false;
    if (deps.isScalarProp) {
      // Fire an event to allow listeners to attempt real-time updates.
      const PreviewUpdateEvent = new ComponentPreviewUpdateEvent(
        deps.selectedComponent,
        deps.propName,
        resolved[deps.propName],
      );
      document.dispatchEvent(PreviewUpdateEvent);
      deps.dispatch(
        // Flag if any listeners were able to perform a real-time update.
        setPreviewBackgroundUpdate(
          PreviewUpdateEvent.getPreviewBackgroundUpdate(),
        ),
      );
      backgroundPreviewUpdate = PreviewUpdateEvent.getPreviewBackgroundUpdate();
    }

    if (isEvaluatedComponentModel(selectedModel) && deps.component) {
      // And then send data to backend - this will:
      // a) Trigger server-side validation/transformation (massaging of widget values)
      // b) Update both the preview and the model - see the pessimistic update
      //    in onQueryStarted in preview.ts
      // @see \Drupal\Core\Field\WidgetInterface::massageFormValues()
      const updateBackend = () => {
        deps.patchComponent(newInputAndUiData, {
          source: syncPropSourcesToResolvedValues(
            selectedModel.source,
            deps.component,
            resolved,
          ),
          resolved,
        });
      };
      if (backgroundPreviewUpdate) {
        // If we're doing a background update, debounce that so we don't make
        // multiple requests for a single update. If we're not doing a
        // background preview update, we should schedule this immediately -
        // debouncing in withRHF will handle preventing this firing too
        // many times in succession.
        scheduleBackendUpdate(
          deps.polledBackgroundUpdate,
          updateBackend,
          POLLED_BACKGROUND_TIMEOUT,
        );
        return;
      }
      // Scalar props of code components skip the form's debounce for the
      // client-side update. Without one, for example in headless mode,
      // debounce their PATCH here instead.
      // @see ComponentFormField
      if (deps.isScalarProp && deps.component.source === 'Code component') {
        scheduleBackendUpdate(
          deps.polledBackgroundUpdate,
          updateBackend,
          DEBOUNCE_TIMEOUT,
        );
        return;
      }
      updateBackend();
      return;
    }
    deps.patchComponent(newInputAndUiData, {
      ...selectedModel,
      resolved,
    });
  };
};

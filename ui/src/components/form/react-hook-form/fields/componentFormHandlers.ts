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

// The PATCH waiting to be sent per component instance, and the editor URL it
// was made on. Each is built from the whole form, so the newest replaces any
// waiting one.
const pendingBackendUpdates = new Map<
  string,
  {
    timer: ReturnType<typeof setTimeout>;
    send: () => unknown;
    editorUrl: string;
  }
>();

const scheduleBackendUpdate = (
  componentUuid: string,
  send: () => unknown,
  delay: number,
) => {
  clearTimeout(pendingBackendUpdates.get(componentUuid)?.timer);
  pendingBackendUpdates.set(componentUuid, {
    timer: setTimeout(() => {
      pendingBackendUpdates.delete(componentUuid);
      send();
    }, delay),
    send,
    editorUrl: window.location.href,
  });
};

/**
 * Sends a component instance's waiting PATCH right away, for example when its
 * form goes away, or before a change built from the stored model, such as
 * linking a prop.
 *
 * Drops it if the editor moved to another entity, whose URL the PATCH would
 * resolve to, or if `isCurrent()` says the change no longer applies.
 *
 * @returns A promise that resolves once the PATCH's response is applied.
 */
export const flushBackendUpdate = async (
  componentUuid: string,
  isCurrent: () => boolean = () => true,
) => {
  const update = pendingBackendUpdates.get(componentUuid);
  if (!update) {
    return;
  }
  clearTimeout(update.timer);
  pendingBackendUpdates.delete(componentUuid);
  if (
    JSON.stringify(extractEntityParams(update.editorUrl)) ===
      JSON.stringify(extractEntityParams(window.location.href)) &&
    isCurrent()
  ) {
    await update.send();
    // ponytail: one timer tick lets the response handler apply the model; a promise from the handler if this ever falls short.
    await new Promise((resolve) => setTimeout(resolve));
  }
};

/**
 * Drops every waiting PATCH, for example before undo or redo replaces the
 * models they were built from.
 */
export const cancelBackendUpdates = () => {
  pendingBackendUpdates.forEach(({ timer }) => clearTimeout(timer));
  pendingBackendUpdates.clear();
};

interface ComponentFormHandlerDependencies {
  dispatch: Dispatch;
  transforms: any;
  selectedComponentType: string;
  selectedComponent: string;
  propName: string;
  isScalarProp: boolean;
  component: any;
  patchComponent: (inputUiData: InputUIData, params: any) => unknown;
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
      const updateBackend = () =>
        deps.patchComponent(newInputAndUiData, {
          source: syncPropSourcesToResolvedValues(
            selectedModel.source,
            deps.component,
            resolved,
          ),
          resolved,
        });
      if (backgroundPreviewUpdate) {
        // If we're doing a background update, debounce that so we don't make
        // multiple requests for a single update. If we're not doing a
        // background preview update, we should schedule this immediately -
        // debouncing in withRHF will handle preventing this firing too
        // many times in succession.
        scheduleBackendUpdate(
          deps.selectedComponent,
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
          deps.selectedComponent,
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

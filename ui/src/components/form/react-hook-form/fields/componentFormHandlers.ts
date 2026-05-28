import {
  isEvaluatedComponentModel,
  syncPropSourcesToResolvedValues,
} from '@/features/layout/layoutModelSlice';
import { setPreviewBackgroundUpdate } from '@/features/pagePreview/previewSlice';

import { getPropsValues } from './componentFormData';
import { ComponentPreviewUpdateEvent } from './componentPreviewEvents';

import type { PropsValues } from '@drupal-canvas/types';
import type { Dispatch } from '@reduxjs/toolkit';
import type { InputUIData } from '@/types/Form';

export const POLLED_BACKGROUND_TIMEOUT = 1000;

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
  polledBackgroundUpdate: React.MutableRefObject<number | null>;
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
        if (deps.polledBackgroundUpdate.current !== null) {
          clearTimeout(deps.polledBackgroundUpdate.current);
        }
        // If we're doing a background update, debounce that so we don't make
        // multiple requests for a single update. If we're not doing a
        // background preview update, we should schedule this immediately -
        // debouncing in withRHF will handle preventing this firing too
        // many times in succession.
        deps.polledBackgroundUpdate.current = setTimeout(
          updateBackend,
          POLLED_BACKGROUND_TIMEOUT,
        ) as any as number;
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

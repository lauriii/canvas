import { useEffect, useRef } from 'react';

import { useAppDispatch, useAppSelector, useAppStore } from '@/app/hooks';
import { useComponentTransforms } from '@/components/ComponentInstanceForm';
import { toPropName } from '@/components/form/react-hook-form/fields/componentFormData';
import { flushBackendUpdate } from '@/components/form/react-hook-form/fields/componentFormHandlers';
import { selectModel } from '@/features/layout/layoutModelSlice';
import { selectEditorFrameContext } from '@/features/ui/uiSlice';
import useInputUIData from '@/hooks/useInputUIData';
import { useGetComponentsQuery } from '@/services/componentAndLayout';
import { usePatchComponent } from '@/services/preview';

import type { PropSourceComponent } from '@/types/Component';

/**
 * Hook for component form inputs - provides all necessary data and utilities
 * for managing component property form fields.
 *
 * @param fieldName - The field name from attributes (name or data-canvas-name)
 * @returns Object containing all component form data and utilities
 */
export const useComponentFormInputInfo = (fieldName: string) => {
  const editorFrameContext = useAppSelector(selectEditorFrameContext);
  const dispatch = useAppDispatch();
  const store = useAppStore();
  const { data: components } = useGetComponentsQuery();
  const transforms = useComponentTransforms();
  const inputAndUiData = useInputUIData();
  const { selectedComponentType, version, selectedComponent } = inputAndUiData;
  const component = components?.[selectedComponentType] as PropSourceComponent;
  const patchComponent = usePatchComponent();

  // A PATCH still waiting when the form goes away, for example when another
  // component is selected, is sent at once, unless its component was deleted.
  const selectedComponentRef = useRef(selectedComponent);
  selectedComponentRef.current = selectedComponent;
  useEffect(
    () => () =>
      flushBackendUpdate(
        selectedComponentRef.current,
        () => !!selectModel(store.getState())[selectedComponentRef.current],
      ),
    [store],
  );

  const propName = toPropName(fieldName, selectedComponent);
  // Scalar prop-types might be able to perform real-time updates.
  const isScalarProp = ['number', 'integer', 'string', 'boolean'].includes(
    component?.propSources?.[propName]?.jsonSchema?.type as string,
  );

  return {
    editorFrameContext,
    dispatch,
    components,
    transforms,
    inputAndUiData,
    selectedComponentType,
    version,
    selectedComponent,
    component,
    patchComponent,
    propName,
    isScalarProp,
  };
};

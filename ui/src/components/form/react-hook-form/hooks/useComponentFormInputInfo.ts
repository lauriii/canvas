import { useRef } from 'react';

import { useAppDispatch, useAppSelector } from '@/app/hooks';
import { useComponentTransforms } from '@/components/ComponentInstanceForm';
import { toPropName } from '@/components/form/react-hook-form/fields/componentFormData';
import { isIconSchema } from '@/components/icons/iconScope';
import { selectEditorFrameContext } from '@/features/ui/uiSlice';
import useInputUIData from '@/hooks/useInputUIData';
import { useGetComponentsQuery } from '@/services/componentAndLayout';
import { useGetIconPacksQuery } from '@/services/icons';
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
  const polledBackgroundUpdate = useRef<number | null>(null);
  const { data: components } = useGetComponentsQuery();
  const transforms = useComponentTransforms();
  const inputAndUiData = useInputUIData();
  const { selectedComponentType, version, selectedComponent } = inputAndUiData;
  const component = components?.[selectedComponentType] as PropSourceComponent;
  const patchComponent = usePatchComponent();

  const propName = toPropName(fieldName, selectedComponent);
  const jsonSchema = component?.propSources?.[propName]?.jsonSchema;
  // Scalar prop-types might be able to perform real-time updates.
  const isScalarProp = ['number', 'integer', 'string', 'boolean'].includes(
    jsonSchema?.type as string,
  );
  // Icon props are scalars, but store the raw `pack_id:icon_id` rather than a
  // renderable value, so the real-time update needs to resolve them first.
  const isIconProp = isIconSchema(jsonSchema);
  // The installed packs let the real-time update resolve an icon id into its
  // inline SVG (or asset URL) without a server round trip. Only fetched when an
  // icon prop is being edited.
  const { data: iconPacks } = useGetIconPacksQuery(undefined, {
    skip: !isIconProp,
  });

  return {
    editorFrameContext,
    dispatch,
    polledBackgroundUpdate,
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
    isIconProp,
    iconPacks,
  };
};

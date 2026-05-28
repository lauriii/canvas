import { useMemo, useRef } from 'react';

import { useAppSelector } from '@/app/hooks';
import { selectCurrentComponent } from '@/features/form/formStateSlice';
import { selectLayout, selectModel } from '@/features/layout/layoutModelSlice';
import { findComponentByUuid } from '@/features/layout/layoutUtils';
import { selectEditorFrameContext } from '@/features/ui/uiSlice';
import { useGetComponentsQuery } from '@/services/componentAndLayout';

import type { InputUIData } from '@/types/Form';

const useInputUIData = (): InputUIData => {
  const currentComponent = useAppSelector(selectCurrentComponent);
  const selectedComponent = currentComponent || 'noop';
  const model = useAppSelector(selectModel);
  const { data: components } = useGetComponentsQuery();
  const layout = useAppSelector(selectLayout);
  const node = findComponentByUuid(layout, selectedComponent);
  const [selectedComponentType, version] = (
    node ? (node.type as string) : 'noop'
  ).split('@');
  const editorFrameContext = useAppSelector(selectEditorFrameContext);
  const prevValuesRef = useRef<any>(null);
  const prevResultRef = useRef<any>(null);

  // Use useMemo to only create new object when dependencies actually change
  const result = useMemo(() => {
    const newResult = {
      selectedComponent,
      components,
      selectedComponentType,
      layout,
      node,
      version,
      model,
      editorFrameContext,
    };

    prevValuesRef.current = newResult;
    return newResult;
  }, [
    selectedComponent,
    components,
    selectedComponentType,
    layout,
    node,
    version,
    model,
    editorFrameContext,
  ]);

  prevResultRef.current = result;

  return result;
};

export default useInputUIData;

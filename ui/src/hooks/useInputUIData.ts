import { useAppSelector } from '@/app/hooks';
import { selectCurrentComponent } from '@/features/form/formStateSlice';
import { selectLayout, selectModel } from '@/features/layout/layoutModelSlice';
import { findComponentByUuid } from '@/features/layout/layoutUtils';
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
  return {
    selectedComponent,
    components,
    selectedComponentType,
    layout,
    node,
    version,
    model,
  };
};

export default useInputUIData;

import clsx from 'clsx';
import { Cross2Icon, TextIcon } from '@radix-ui/react-icons';
import { Box, Flex, Text } from '@radix-ui/themes';

import { useAppSelector } from '@/app/hooks';
import InputDescription from '@/components/form/components/drupal/InputDescription';
import {
  isEvaluatedComponentModel,
  selectLayout,
  selectModel,
} from '@/features/layout/layoutModelSlice';
import { findComponentByUuid } from '@/features/layout/layoutUtils';
import {
  EditorFrameContext,
  selectSelectedComponentUuid,
} from '@/features/ui/uiSlice';
import { useGetComponentsQuery } from '@/services/componentAndLayout';
import { useUpdateComponentMutation } from '@/services/preview';

import type {
  ComponentModel,
  EvaluatedComponentModel,
} from '@/features/layout/layoutModelSlice';
import type {
  CanvasComponent,
  DefaultValues,
  FieldDataItem,
  PropSourceComponent,
} from '@/types/Component';

import styles from './LinkedFieldBox.module.css';

const LinkedFieldBox = ({
  title,
  propName,
  description,
  descriptionDisplay,
}: {
  title: string;
  propName: string;
  description: string;
  descriptionDisplay?: 'before' | 'after' | 'invisible';
}) => {
  const { data: components } = useGetComponentsQuery();
  const model = useAppSelector(selectModel);
  const layout = useAppSelector(selectLayout);
  const selectedComponent = useAppSelector(selectSelectedComponentUuid);
  const selectedComponentId: string = selectedComponent || 'noop';
  const selectedModel: ComponentModel | EvaluatedComponentModel =
    model[selectedComponentId] || {};
  const node = findComponentByUuid(layout, selectedComponentId);
  const [selectedComponentType, version] = (
    node ? (node.type as string) : 'noop'
  ).split('@');
  const [patchComponent] = useUpdateComponentMutation({
    fixedCacheKey: selectedComponentId,
  });
  const unlinkField = () => {
    const component: CanvasComponent | undefined =
      components?.[selectedComponentType];
    if (!component) {
      return;
    }

    const propData: FieldDataItem | undefined = (
      component as PropSourceComponent
    ).propSources?.[propName];
    if (!propData) {
      return;
    }
    const default_values: DefaultValues = propData?.default_values || {};
    if (isEvaluatedComponentModel(selectedModel)) {
      patchComponent({
        type: EditorFrameContext.TEMPLATE,
        componentInstanceUuid: selectedComponentId,
        componentType: `${selectedComponentType}@${version}`,
        model: {
          source: {
            ...selectedModel.source,
            [propName]: {
              expression: propData.expression,
              sourceType: propData.sourceType,
              sourceTypeSettings: propData.sourceTypeSettings,
            },
          },
          resolved: {
            ...selectedModel.resolved,
            [propName]: default_values.resolved,
          },
        },
      });
    }
  };

  return (
    <Box mb="4">
      <InputDescription
        description={description}
        descriptionDisplay={descriptionDisplay}
      >
        <Flex className={styles.wrapper} mb="2">
          <Text className={clsx(styles.linkIcon, styles.iconBox)}>
            <TextIcon />
          </Text>
          <Text className={styles.text}>{title}</Text>
          <button
            className={clsx(styles.iconBox, styles.closeIcon)}
            onClick={unlinkField}
          >
            <Cross2Icon />
          </button>
        </Flex>
      </InputDescription>
    </Box>
  );
};

export default LinkedFieldBox;

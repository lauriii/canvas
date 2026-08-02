import clsx from 'clsx';
import { Cross2Icon, TextIcon } from '@radix-ui/react-icons';
import { Box, Flex, Text } from '@radix-ui/themes';

import InputDescription from '@/components/form/components/drupal/InputDescription';
import useInputUIData from '@/hooks/useInputUIData';
import { usePatchProp } from '@/services/preview';

import type {
  CanvasComponent,
  DefaultValues,
  FieldDataItem,
  PropSourceComponent,
} from '@/types/Component';

import styles from './LinkedFieldBox.module.css';

const ARROW_SEPARATOR = ' → ';
const SLASH_SEPARATOR = ' / ';

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
  // Convert arrows to slashes for the full label display
  const fullLabel = title.replaceAll(ARROW_SEPARATOR, SLASH_SEPARATOR);
  // Extract just the last segment for the short title
  const parts = fullLabel.split(SLASH_SEPARATOR);
  const shortTitle = parts[parts.length - 1];

  const inputUIData = useInputUIData();
  const { components, selectedComponentType } = inputUIData;
  const patchProp = usePatchProp();
  const propSchema = (
    components?.[selectedComponentType] as PropSourceComponent | undefined
  )?.propSources?.[propName]?.jsonSchema;
  // A bounded array prop renders the mapped field's first `maxItems` values.
  // Say so next to the mapping: it is a decision by the component author, not
  // a mistake by the site builder, so it reads as a description, not a warning.
  const maxItems =
    propSchema?.type === 'array' ? propSchema.maxItems : undefined;
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
    patchProp(
      inputUIData,
      propName,
      {
        expression: propData.expression,
        sourceType: propData.sourceType,
        sourceTypeSettings: propData.sourceTypeSettings,
      },
      default_values.resolved,
    );
  };

  return (
    <Box mb="4" data-testid={`linked-field-box-${propName}`}>
      <InputDescription
        description={description}
        descriptionDisplay={descriptionDisplay}
      >
        <Flex className={styles.wrapper} mb="2" title={fullLabel}>
          <Text className={clsx(styles.linkIcon, styles.iconBox)}>
            <TextIcon />
          </Text>
          <Text
            data-testid={`linked-field-label-${propName}`}
            className={styles.text}
          >
            {shortTitle}
          </Text>
          <button
            className={clsx(styles.iconBox, styles.closeIcon)}
            onClick={unlinkField}
          >
            <Cross2Icon />
          </button>
        </Flex>
      </InputDescription>
      {maxItems !== undefined && (
        <Box data-testid={`linked-field-truncation-${propName}`}>
          <InputDescription
            description={
              maxItems === 1
                ? 'Shows the first value of this field.'
                : `Shows the first ${maxItems} values of this field.`
            }
          >
            {null}
          </InputDescription>
        </Box>
      )}
    </Box>
  );
};

export default LinkedFieldBox;

import { Box, Flex, Text, TextField } from '@radix-ui/themes';

import { useAppDispatch } from '@/app/hooks';
import {
  Divider,
  FormElement,
} from '@/features/code-editor/component-data/FormElement';
import { PropValuesSortableList } from '@/features/code-editor/component-data/forms/PropValuesSortableList';
import {
  createArrayDragEndHandler,
  handleArrayAdd,
  handleArrayRemove,
  handleArrayValueChange,
} from '@/features/code-editor/utils/arrayPropUtils';
import {
  VALUE_MODE_LIMITED,
  VALUE_MODE_UNLIMITED,
} from '@/types/CodeComponent';

import type { CodeComponentProp, ValueMode } from '@/types/CodeComponent';

/**
 * Renders a form input for array-type props in a code component.
 * Supports limited and unlimited modes (see CodeComponent::ValueMode).
 */
export default function FormPropTypeArray({
  id,
  example = [],
  itemType = 'string',
  isDisabled = false,
  valueMode = VALUE_MODE_UNLIMITED,
  limitedCount = 1,
}: Pick<CodeComponentProp, 'id'> & {
  example: string[] | number[];
  itemType: 'string' | 'integer' | 'number';
  isDisabled?: boolean;
  valueMode?: ValueMode;
  limitedCount?: number;
}) {
  const dispatch = useAppDispatch();

  const exampleArray = Array.isArray(example) ? example : [];

  // Ensure we always have at least one item to display in unlimited mode
  const displayArray =
    exampleArray.length === 0 ? ([''] as (string | number)[]) : exampleArray;

  const handleDragEnd = createArrayDragEndHandler(displayArray, dispatch, id);

  const handleAdd = () => {
    handleArrayAdd(displayArray, dispatch, id, '');
  };

  const handleRemove = (index: number) => {
    handleArrayRemove(displayArray, dispatch, id, index);
  };

  const handleValueChange = (index: number, value: string | number) => {
    handleArrayValueChange(displayArray, dispatch, id, index, value);
  };

  const renderInputField = (index: number) => (
    <Box flexGrow="1" flexShrink="1">
      <TextField.Root
        autoComplete="off"
        data-testid={`array-prop-value-${id}-${index}`}
        id={`array-prop-value-${id}-${index}`}
        type={
          itemType === 'integer' || itemType === 'number' ? 'number' : 'text'
        }
        step={itemType === 'integer' ? 1 : undefined}
        value={
          itemType === 'integer' || itemType === 'number'
            ? displayArray[index] === '' || displayArray[index] === undefined
              ? ''
              : String(displayArray[index])
            : String(displayArray[index] ?? '')
        }
        size="1"
        onChange={(e) => {
          const inputValue = e.target.value;
          let value;
          if (itemType === 'integer' || itemType === 'number') {
            value = inputValue === '' ? '' : Number(inputValue);
          } else {
            value = inputValue;
          }
          handleValueChange(index, value);
        }}
        placeholder={
          {
            string: 'Enter a text value',
            integer: 'Enter an integer',
            number: 'Enter a number',
          }[itemType]
        }
        disabled={isDisabled}
      />
    </Box>
  );

  return (
    <Flex direction="column" gap="4" flexGrow="1">
      <Divider />
      <FormElement>
        <Text size="1" weight="medium" as="div">
          Example value
        </Text>
        <PropValuesSortableList
          items={
            valueMode === VALUE_MODE_LIMITED
              ? Array.from({ length: limitedCount }).map((_, index) => index)
              : displayArray.map((_, index) => index)
          }
          renderItem={renderInputField}
          onDragEnd={handleDragEnd}
          onRemove={
            valueMode === VALUE_MODE_UNLIMITED ? handleRemove : undefined
          }
          onAdd={valueMode === VALUE_MODE_UNLIMITED ? handleAdd : undefined}
          isDisabled={isDisabled}
          mode={valueMode}
        />
      </FormElement>
    </Flex>
  );
}

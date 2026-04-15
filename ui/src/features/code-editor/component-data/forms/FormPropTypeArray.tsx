import { useEffect, useMemo, useState } from 'react';
import clsx from 'clsx';
import { arrayMove } from '@dnd-kit/sortable';
import { Box, Flex, Text, TextField } from '@radix-ui/themes';

import { useAppDispatch } from '@/app/hooks';
import {
  Divider,
  FormElement,
} from '@/features/code-editor/component-data/FormElement';
import { PropValuesSortableList } from '@/features/code-editor/component-data/forms/PropValuesSortableList';
import {
  createArrayDragEndHandler,
  createDisplayArray,
  handleArrayAdd,
  handleArrayRemove,
  handleArrayValueChange,
} from '@/features/code-editor/utils/arrayPropUtils';
import { getNumericInputError } from '@/features/code-editor/utils/numericInputUtils';
import { VALUE_MODE_UNLIMITED } from '@/types/CodeComponent';

import type { CodeComponentProp, ValueMode } from '@/types/CodeComponent';

import styles from '@/features/code-editor/component-data/FormElement.module.css';

/**
 * Input for a single numeric array item (integer or number).
 */
function NumericArrayItem({
  propId,
  index,
  value,
  itemType,
  isDisabled,
  errorMessage,
  onValueChange,
  onErrorChange,
}: {
  propId: string;
  index: number;
  value: string | number;
  itemType: 'integer' | 'number';
  isDisabled: boolean;
  errorMessage: string;
  onValueChange: (index: number, value: string | number) => void;
  onErrorChange: (index: number, error: string) => void;
}) {
  return (
    <Box flexGrow="1" flexShrink="1">
      <TextField.Root
        autoComplete="off"
        data-testid={`array-prop-value-${propId}-${index}`}
        id={`array-prop-value-${propId}-${index}`}
        type="number"
        step={itemType === 'integer' ? 1 : 'any'}
        value={value === '' || value == null ? '' : String(value)}
        size="1"
        placeholder={
          itemType === 'integer' ? 'Enter an integer' : 'Enter a number'
        }
        onChange={(e) => {
          const raw = e.target.value;
          const error = getNumericInputError(raw, itemType);
          if (error) {
            onErrorChange(index, error);
            return;
          }
          onErrorChange(index, '');
          onValueChange(index, raw === '' ? '' : Number(raw));
        }}
        disabled={isDisabled}
        className={clsx({ [styles.error]: !!errorMessage })}
        {...(errorMessage ? { 'data-invalid-prop-value': true } : {})}
      />
      {errorMessage && (
        <Text color="red" size="1">
          {errorMessage}
        </Text>
      )}
    </Box>
  );
}

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
  const [itemErrors, setItemErrors] = useState<string[]>([]);
  useEffect(() => {
    setItemErrors([]);
  }, [itemType]);

  const displayArray = useMemo(
    () => createDisplayArray(example, valueMode, limitedCount),
    [example, valueMode, limitedCount],
  );

  const handleDragEnd = createArrayDragEndHandler(
    displayArray,
    dispatch,
    id,
    undefined,
    (oldIndex, newIndex) => {
      setItemErrors((prev) => arrayMove([...prev], oldIndex, newIndex));
    },
  );

  const handleAdd = () => {
    // Use empty string as default to match single-value component behavior
    // (no default value unless explicitly set or required)
    handleArrayAdd(displayArray, dispatch, id, '');
  };

  const handleRemove = (index: number) => {
    setItemErrors((prev) => prev.filter((_, i) => i !== index));
    handleArrayRemove(displayArray, dispatch, id, index);
  };

  const handleValueChange = (index: number, value: string | number) => {
    handleArrayValueChange(displayArray, dispatch, id, index, value);
  };

  const renderInputField = (index: number) => {
    if (itemType === 'integer' || itemType === 'number') {
      return (
        <NumericArrayItem
          propId={id}
          index={index}
          value={displayArray[index] ?? ''}
          itemType={itemType}
          isDisabled={isDisabled}
          errorMessage={itemErrors[index] ?? ''}
          onValueChange={handleValueChange}
          onErrorChange={(idx, error) => {
            setItemErrors((prev) => {
              const next = [...prev];
              next[idx] = error;
              return next;
            });
          }}
        />
      );
    }

    return (
      <Box flexGrow="1" flexShrink="1">
        <TextField.Root
          autoComplete="off"
          data-testid={`array-prop-value-${id}-${index}`}
          id={`array-prop-value-${id}-${index}`}
          type="text"
          value={String(displayArray[index] ?? '')}
          size="1"
          onChange={(e) => {
            handleValueChange(index, e.target.value);
          }}
          placeholder="Enter a text value"
        />
      </Box>
    );
  };

  return (
    <Flex direction="column" gap="4" flexGrow="1">
      <Divider />
      <FormElement>
        <Text size="1" weight="medium" as="div">
          Example value
        </Text>
        <PropValuesSortableList
          items={displayArray.map((_, index) => index)}
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

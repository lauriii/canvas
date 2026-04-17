import { useEffect, useMemo, useRef, useState } from 'react';
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
  DEFAULT_EXAMPLES,
  REQUIRED_EXAMPLE_ERROR_MESSAGE,
} from '@/features/code-editor/component-data/Props';
import {
  useRequiredProp,
  useSyncRequiredArrayError,
} from '@/features/code-editor/hooks/useRequiredProp';
import {
  createArrayDragEndHandler,
  createDisplayArray,
  dispatchUpdateProp,
  handleArrayAdd,
  handleArrayRemove,
  handleArrayValueChange,
  hasNonEmptyArrayValue,
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
  const [displayValue, setDisplayValue] = useState(String(value ?? ''));

  // Keep a ref so the effect below can read the latest displayValue without
  // adding it as a dependency (which would re-run on every keystroke and
  // strip trailing zeros such as "10.0" while the user is still typing).
  const displayValueRef = useRef(displayValue);
  displayValueRef.current = displayValue;

  // Sync the display string when the store value changes externally (e.g. on
  // reorder or add/remove), but NOT when the difference is only a trailing
  // zero (e.g. display "10.0" while store holds 10). Comparing numerically
  // lets the user keep typing "10.0" without the zero being stripped.
  useEffect(() => {
    const storedStr = String(value ?? '');
    const displayNum =
      displayValueRef.current !== '' ? Number(displayValueRef.current) : null;
    const storedNum = storedStr !== '' ? Number(storedStr) : null;
    if (displayNum !== storedNum) {
      setDisplayValue(storedStr);
    }
  }, [value]);

  return (
    <Box flexGrow="1" flexShrink="1">
      <TextField.Root
        autoComplete="off"
        data-testid={`array-prop-value-${propId}-${index}`}
        id={`array-prop-value-${propId}-${index}`}
        type="number"
        step={itemType === 'integer' ? 1 : 'any'}
        value={displayValue}
        size="1"
        placeholder={
          itemType === 'integer' ? 'Enter an integer' : 'Enter a number'
        }
        onChange={(e) => {
          const raw = e.target.value;
          setDisplayValue(raw);
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
  required = false,
  valueMode = VALUE_MODE_UNLIMITED,
  limitedCount = 1,
}: Pick<CodeComponentProp, 'id'> & {
  example: string[] | number[];
  itemType: 'string' | 'integer' | 'number';
  isDisabled?: boolean;
  required?: boolean;
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

  // Check if the array has at least one non-empty value.
  const hasNonEmptyValue = hasNonEmptyArrayValue(example);

  // Use a normalized example value for the hook since `![]` is false in JS,
  // which would prevent the hook from detecting empty arrays.
  const { showRequiredError, setShowRequiredError } = useRequiredProp(
    required,
    hasNonEmptyValue ? 'non-empty' : '',
    () => {
      // Prefill with a default value when required is toggled on.
      const exampleKey = itemType === 'string' ? 'text' : itemType;
      dispatchUpdateProp(dispatch, id, {
        example: [DEFAULT_EXAMPLES[exampleKey]],
      });
    },
    [dispatch, id, itemType],
  );

  // Keep error state in sync with whether the array has non-empty values.
  useSyncRequiredArrayError(required, hasNonEmptyValue, setShowRequiredError);

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
          errorMessage={
            showRequiredError && (
              <Text color="red" size="1">
                {REQUIRED_EXAMPLE_ERROR_MESSAGE}
              </Text>
            )
          }
        />
      </FormElement>
    </Flex>
  );
}

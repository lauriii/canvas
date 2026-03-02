import { useEffect, useRef, useState } from 'react';
import clsx from 'clsx';
import { arrayMove } from '@dnd-kit/sortable'; // Import directly for local handling
import { Box, Flex, Select, Text, TextField } from '@radix-ui/themes';

import { useAppDispatch } from '@/app/hooks';
import { updateProp } from '@/features/code-editor/codeEditorSlice';
import {
  Divider,
  FormElement,
  Label,
} from '@/features/code-editor/component-data/FormElement';
import { PropValuesSortableList } from '@/features/code-editor/component-data/forms/PropValuesSortableList';
import {
  DEFAULT_EXAMPLES,
  REQUIRED_EXAMPLE_ERROR_MESSAGE,
} from '@/features/code-editor/component-data/Props';
import { useRequiredProp } from '@/features/code-editor/hooks/useRequiredProp';
import {
  handleArrayAdd,
  handleArrayRemove,
  handleArrayValueChange,
} from '@/features/code-editor/utils/arrayPropUtils';
import {
  VALUE_MODE_LIMITED,
  VALUE_MODE_UNLIMITED,
} from '@/types/CodeComponent';
import {
  localTimeToUtcConversion,
  utcToLocalTimeConversion,
} from '@/utils/date-utils';

import type { CodeComponentProp, ValueMode } from '@/types/CodeComponent';

import styles from '@/features/code-editor/component-data/FormElement.module.css';

const generateLocalId = () => Math.random().toString(36).substring(2, 9);

export default function FormPropTypeDate({
  id,
  example,
  format,
  isDisabled = false,
  required,
  allowMultiple = false,
  valueMode = VALUE_MODE_UNLIMITED,
  limitedCount = 1,
}: Pick<CodeComponentProp, 'id'> & {
  example: string | string[];
  format: string;
  isDisabled?: boolean;
  required: boolean;
  allowMultiple?: boolean;
  valueMode?: ValueMode;
  limitedCount?: number;
}) {
  const dispatch = useAppDispatch();
  // @ts-ignore
  const [dateType, setDateType] = useState<'date' | 'date-time'>(format);
  const [isExampleValueValid, setIsExampleValueValid] = useState(true);

  // The datetime format the server requires is in UTC ISO string, but the input element of type "datetime-local"
  // requires a local datetime format. We need to convert between these two formats.
  const [datetimeLocalForInput, setDatetimeLocalForInput] = useState(
    utcToLocalTimeConversion(typeof example === 'string' ? example : ''),
  );

  // 1. Local items state with persistent IDs
  const [localItems, setLocalItems] = useState<{ id: string; value: string }[]>(
    () => {
      const exampleArray = Array.isArray(example) ? example : [];
      const initial = exampleArray.length === 0 ? [''] : exampleArray;
      return initial.map((v) => ({ id: generateLocalId(), value: v }));
    },
  );

  // 2. Ref to prevent feedback loops between Redux and local state
  const isInternalUpdate = useRef(false);

  useEffect(() => {
    // Only update local items if the change came from OUTSIDE (e.g. Redux/Undo)
    if (!isInternalUpdate.current) {
      const exampleArray = Array.isArray(example) ? example : [];
      const normalized = exampleArray.length === 0 ? [''] : exampleArray;
      setLocalItems((prev) => {
        // Match existing IDs to new values to maintain input focus and state
        return normalized.map((val, idx) => ({
          id: prev[idx]?.id || generateLocalId(),
          value: val,
        }));
      });
    }
    isInternalUpdate.current = false;
  }, [example]);

  const handleDragEnd = (event: any) => {
    const { active, over } = event;

    if (over && active.id !== over.id) {
      const oldIndex = localItems.findIndex((i) => i.id === active.id);
      const newIndex = localItems.findIndex((i) => i.id === over.id);

      // Perform local move
      const updatedLocalItems = arrayMove(localItems, oldIndex, newIndex);

      // Set flag to ignore the next useEffect sync
      isInternalUpdate.current = true;
      setLocalItems(updatedLocalItems);

      // Dispatch the new string array to Redux
      const newValues = updatedLocalItems.map((i) => i.value);
      dispatch(updateProp({ id, updates: { example: newValues } }));
    }
  };

  const handleAdd = () => {
    isInternalUpdate.current = true;
    const newItem = { id: generateLocalId(), value: '' };
    setLocalItems((prev) => [...prev, newItem]);

    handleArrayAdd(
      localItems.map((i) => i.value),
      dispatch,
      id,
      '',
    );
    setMultiValueValidityStates((prev) => [...prev, true]);
  };

  const handleRemove = (index: number) => {
    isInternalUpdate.current = true;
    setLocalItems((prev) => prev.filter((_, i) => i !== index));

    handleArrayRemove(
      localItems.map((i) => i.value),
      dispatch,
      id,
      index,
    );
    setMultiValueValidityStates((prev) => {
      const next = [...prev];
      next.splice(index, 1);
      return next;
    });
  };

  const handleMultiValueChange = (index: number, value: string) => {
    isInternalUpdate.current = true;
    setLocalItems((prev) => {
      const next = [...prev];
      next[index] = { ...next[index], value };
      return next;
    });

    const convertedValue =
      dateType === 'date-time' ? localTimeToUtcConversion(value) : value;
    handleArrayValueChange(
      localItems.map((i) => i.value),
      dispatch,
      id,
      index,
      convertedValue,
    );

    setMultiValueValidityStates((prev) => {
      const next = [...prev];
      next[index] = true;
      return next;
    });
  };

  const [multiValueValidityStates, setMultiValueValidityStates] = useState<
    boolean[]
  >(() => localItems.map(() => true));

  useEffect(() => {
    if (
      valueMode === VALUE_MODE_LIMITED &&
      limitedCount > multiValueValidityStates.length
    ) {
      setMultiValueValidityStates((prev) => {
        const next = [...prev];
        while (next.length < limitedCount) {
          next.push(true);
        }
        return next;
      });
    }
  }, [limitedCount, valueMode, multiValueValidityStates.length]);

  const handleMultiValueBlur = (
    index: number,
    e: React.FocusEvent<HTMLInputElement>,
  ) => {
    setMultiValueValidityStates((prev) => {
      const next = [...prev];
      next[index] = e.target.validity.valid;
      return next;
    });
  };

  const renderInputField = (index: number) => (
    <Box flexGrow="1">
      <TextField.Root
        key={localItems[index].id}
        data-testid={`array-prop-value-${id}-${index}`}
        id={`array-prop-value-${id}-${localItems[index].id}`}
        size="1"
        value={
          dateType === 'date-time'
            ? utcToLocalTimeConversion(localItems[index].value || '')
            : localItems[index].value || ''
        }
        disabled={isDisabled}
        type={dateType === 'date' ? 'date' : 'datetime-local'}
        onChange={(e) => handleMultiValueChange(index, e.target.value)}
        onBlur={(e) => handleMultiValueBlur(index, e)}
        className={clsx({ [styles.error]: !multiValueValidityStates[index] })}
      />
    </Box>
  );
  const defaultValue = DEFAULT_EXAMPLES[dateType] as string;

  const { showRequiredError, setShowRequiredError } = useRequiredProp(
    required,
    example,
    () => {
      dispatch(
        updateProp({
          id,
          updates: { example: defaultValue, format: dateType },
        }),
      );
      if (dateType === 'date-time') {
        setDatetimeLocalForInput(utcToLocalTimeConversion(defaultValue));
      }
    },
    [dispatch, id, dateType],
  );

  return (
    <Flex direction="column" gap="4" flexGrow="1">
      <FormElement>
        <Label htmlFor={`prop-date-type-${id}`}>Date type</Label>
        <Select.Root
          value={dateType}
          onValueChange={(value: 'date' | 'date-time') => {
            setIsExampleValueValid(true);
            setDateType(value);

            // Get the default value for the new date type
            const newDefaultValue = DEFAULT_EXAMPLES[value] as string;

            // In multi-value mode, reset the array to a single default value
            if (allowMultiple) {
              isInternalUpdate.current = true;
              const newItem = { id: generateLocalId(), value: newDefaultValue };
              setLocalItems([newItem]);
              setMultiValueValidityStates([true]);

              dispatch(
                updateProp({
                  id,
                  updates: {
                    example: [newDefaultValue],
                    format: value,
                    valueMode: VALUE_MODE_UNLIMITED,
                    limitedCount: undefined,
                  },
                }),
              );
            } else {
              // Single value mode - just update format and example
              dispatch(
                updateProp({
                  id,
                  updates: { example: newDefaultValue, format: value },
                }),
              );
              if (value === 'date-time') {
                setDatetimeLocalForInput(
                  utcToLocalTimeConversion(newDefaultValue),
                );
              }
            }
          }}
          size="1"
          disabled={isDisabled}
        >
          <Select.Trigger id={`prop-date-type-${id}`} />
          <Select.Content>
            <Select.Item value="date">Date only</Select.Item>
            <Select.Item value="date-time">Date and time</Select.Item>
          </Select.Content>
        </Select.Root>
      </FormElement>
      <Divider />
      <FormElement>
        <Label htmlFor={`prop-example-${id}`}>Example value</Label>
        {/* Single value mode */}
        {!allowMultiple && (
          <TextField.Root
            id={`prop-example-${id}`}
            size="1"
            value={
              dateType === 'date' ? (example as string) : datetimeLocalForInput
            }
            type={dateType === 'date' ? 'date' : 'datetime-local'}
            onChange={(e) => {
              const value = e.target.value;
              // Show/hide error based on whether field is empty while required
              setShowRequiredError(required && !value);
              // Convert the datetime-local value to UTC ISO string for the server.
              const convertedValue =
                dateType === 'date-time'
                  ? localTimeToUtcConversion(value)
                  : value;
              dispatch(
                updateProp({
                  id,
                  updates: { example: convertedValue, format: dateType },
                }),
              );
              if (dateType === 'date-time') {
                setDatetimeLocalForInput(value);
              }
            }}
            onBlur={(e) => {
              setIsExampleValueValid(e.target.validity.valid);
            }}
            className={clsx({
              [styles.error]: !isExampleValueValid || showRequiredError,
            })}
            {...(!isExampleValueValid || showRequiredError
              ? { 'data-invalid-prop-value': true }
              : {})}
          />
        )}

        {/* Multiple values mode */}
        {allowMultiple && (
          <PropValuesSortableList
            items={localItems.map((item) => item.id)}
            renderItem={renderInputField}
            onDragEnd={handleDragEnd}
            onRemove={
              valueMode === VALUE_MODE_UNLIMITED ? handleRemove : undefined
            }
            onAdd={valueMode === VALUE_MODE_UNLIMITED ? handleAdd : undefined}
            isDisabled={isDisabled}
            mode={valueMode}
          />
        )}
        {showRequiredError && (
          <Text color="red" size="1">
            {REQUIRED_EXAMPLE_ERROR_MESSAGE}
          </Text>
        )}
      </FormElement>
    </Flex>
  );
}

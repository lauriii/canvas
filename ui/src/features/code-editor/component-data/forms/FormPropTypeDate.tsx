import { useEffect, useMemo, useState } from 'react';
import clsx from 'clsx';
import { Box, Flex, Select, Text, TextField } from '@radix-ui/themes';

import { useAppDispatch } from '@/app/hooks';
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

  // For multiple values mode
  // Memoize to prevent unnecessary recomputations
  const displayArray = useMemo(
    () =>
      createDisplayArray(
        example,
        valueMode,
        limitedCount,
        allowMultiple,
      ) as string[],
    [example, valueMode, limitedCount, allowMultiple],
  );

  const handleDragEnd = createArrayDragEndHandler(displayArray, dispatch, id, {
    format: dateType,
  });

  const handleAdd = () => {
    handleArrayAdd(displayArray, dispatch, id, '', { format: dateType });
    setMultiValueValidityStates((prev) => [...prev, true]);
  };

  const handleRemove = (index: number) => {
    handleArrayRemove(displayArray, dispatch, id, index, { format: dateType });
    setMultiValueValidityStates((prev) => {
      const next = [...prev];
      next.splice(index, 1);
      return next;
    });
  };

  const handleMultiValueChange = (index: number, value: string) => {
    // Convert the value to the format we want to store (UTC for date-time, raw for date)
    const convertedValue =
      dateType === 'date-time' ? localTimeToUtcConversion(value) : value;

    handleArrayValueChange(displayArray, dispatch, id, index, convertedValue, {
      format: dateType,
    });

    setMultiValueValidityStates((prev) => {
      const next = [...prev];
      next[index] = true;
      return next;
    });
  };

  const [multiValueValidityStates, setMultiValueValidityStates] = useState<
    boolean[]
  >(() => displayArray.map(() => true));

  // Sync validity states with displayArray length changes
  useEffect(() => {
    setMultiValueValidityStates((prev) => {
      const newLength = displayArray.length;
      if (prev.length === newLength) return prev;
      if (newLength > prev.length) {
        return [...prev, ...Array(newLength - prev.length).fill(true)];
      }
      return prev.slice(0, newLength);
    });
  }, [displayArray.length]);

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
        data-testid={`array-prop-value-${id}-${index}`}
        id={`array-prop-value-${id}-${index}`}
        size="1"
        value={
          dateType === 'date-time'
            ? utcToLocalTimeConversion(displayArray[index] || '')
            : displayArray[index] || ''
        }
        type={dateType === 'date' ? 'date' : 'datetime-local'}
        onChange={(e) => handleMultiValueChange(index, e.target.value)}
        onBlur={(e) => handleMultiValueBlur(index, e)}
        className={clsx({ [styles.error]: !multiValueValidityStates[index] })}
      />
    </Box>
  );
  const defaultValue = DEFAULT_EXAMPLES[dateType] as string;

  // For multi-value mode, normalize the example so `useRequiredProp` can
  // detect empty arrays (since `![]` is false in JS).
  const hasNonEmptyValue = hasNonEmptyArrayValue(allowMultiple ? example : []);

  const { showRequiredError, setShowRequiredError } = useRequiredProp(
    required,
    allowMultiple ? (hasNonEmptyValue ? 'non-empty' : '') : example,
    () => {
      if (allowMultiple) {
        dispatchUpdateProp(dispatch, id, {
          example: [defaultValue],
          format: dateType,
        });
      } else {
        dispatchUpdateProp(dispatch, id, {
          example: defaultValue,
          format: dateType,
        });
        if (dateType === 'date-time') {
          setDatetimeLocalForInput(utcToLocalTimeConversion(defaultValue));
        }
      }
    },
    [dispatch, id, dateType, allowMultiple],
  );

  // Keep error state in sync with whether the array has non-empty values.
  useSyncRequiredArrayError(
    required,
    hasNonEmptyValue,
    setShowRequiredError,
    allowMultiple,
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

            // Only prefill a default example when the prop is required;
            // otherwise leave the example empty (matching behavior in Props.tsx).
            const newDefaultValue = required
              ? (DEFAULT_EXAMPLES[value] as string)
              : '';

            // In multi-value mode, reset the array to a single default value
            if (allowMultiple) {
              setMultiValueValidityStates([true]);

              dispatchUpdateProp(dispatch, id, {
                example: [newDefaultValue],
                format: value,
                valueMode: VALUE_MODE_UNLIMITED,
                limitedCount: undefined,
              });
            } else {
              // Single value mode - just update format and example
              dispatchUpdateProp(dispatch, id, {
                example: newDefaultValue,
                format: value,
              });
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
              dispatchUpdateProp(dispatch, id, {
                example: convertedValue,
                format: dateType,
              });
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
        )}
        {!allowMultiple && showRequiredError && (
          <Text color="red" size="1">
            {REQUIRED_EXAMPLE_ERROR_MESSAGE}
          </Text>
        )}
      </FormElement>
    </Flex>
  );
}

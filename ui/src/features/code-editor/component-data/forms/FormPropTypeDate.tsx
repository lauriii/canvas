import { useState } from 'react';
import clsx from 'clsx';
import { Flex, Select, Text, TextField } from '@radix-ui/themes';

import { useAppDispatch } from '@/app/hooks';
import { updateProp } from '@/features/code-editor/codeEditorSlice';
import {
  Divider,
  FormElement,
  Label,
} from '@/features/code-editor/component-data/FormElement';
import {
  DEFAULT_EXAMPLES,
  REQUIRED_EXAMPLE_ERROR_MESSAGE,
} from '@/features/code-editor/component-data/Props';
import { useRequiredProp } from '@/features/code-editor/hooks/useRequiredProp';
import {
  localTimeToUtcConversion,
  utcToLocalTimeConversion,
} from '@/utils/date-utils';

import type { CodeComponentProp } from '@/types/CodeComponent';

import styles from '@/features/code-editor/component-data/FormElement.module.css';

export default function FormPropTypeDate({
  id,
  example,
  format,
  isDisabled = false,
  required,
}: Pick<CodeComponentProp, 'id'> & {
  example: string;
  format: string;
  isDisabled?: boolean;
  required: boolean;
}) {
  const dispatch = useAppDispatch();
  // @ts-ignore
  const [dateType, setDateType] = useState<'date' | 'date-time'>(format);
  const [isExampleValueValid, setIsExampleValueValid] = useState(true);
  // The datetime format the server requires is in UTC ISO string, but the input element of type "datetime-local"
  // requires a local datetime format. We need to convert between these two formats.
  const [datetimeLocalForInput, setDatetimeLocalForInput] = useState(
    utcToLocalTimeConversion(example),
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
            dispatch(
              updateProp({
                id,
                updates: { format: value },
              }),
            );
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
        <TextField.Root
          id={`prop-example-${id}`}
          size="1"
          value={dateType === 'date' ? example : datetimeLocalForInput}
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
        {showRequiredError && (
          <Text color="red" size="1">
            {REQUIRED_EXAMPLE_ERROR_MESSAGE}
          </Text>
        )}
      </FormElement>
    </Flex>
  );
}

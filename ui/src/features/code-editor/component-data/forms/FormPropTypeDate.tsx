import { useState } from 'react';
import clsx from 'clsx';
import { Flex, Select, TextField } from '@radix-ui/themes';

import { useAppDispatch } from '@/app/hooks';
import { updateProp } from '@/features/code-editor/codeEditorSlice';
import {
  Divider,
  FormElement,
  Label,
} from '@/features/code-editor/component-data/FormElement';
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
}: Pick<CodeComponentProp, 'id'> & {
  example: string;
  format: string;
  isDisabled?: boolean;
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
          disabled={isDisabled}
          type={dateType === 'date' ? 'date' : 'datetime-local'}
          onChange={(e) => {
            const value = e.target.value;
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
            [styles.error]: !isExampleValueValid,
          })}
          {...(!isExampleValueValid ? { 'data-invalid-prop-value': true } : {})}
        />
      </FormElement>
    </Flex>
  );
}

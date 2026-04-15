import { useEffect, useState } from 'react';
import clsx from 'clsx';
import { Flex, Text, TextField } from '@radix-ui/themes';

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
import { getNumericInputError } from '@/features/code-editor/utils/numericInputUtils';

import type { CodeComponentProp } from '@/types/CodeComponent';

import styles from '@/features/code-editor/component-data/FormElement.module.css';

export default function FormPropTypeTextField({
  id,
  example,
  type = 'string',
  isDisabled = false,
  required,
}: Pick<CodeComponentProp, 'id'> & {
  example: string;
  type?: 'string' | 'integer' | 'number';
  isDisabled?: boolean;
  required: boolean;
}) {
  const dispatch = useAppDispatch();
  const isNumeric = type === 'integer' || type === 'number';

  // Single string state: empty means no error, non-empty is the error message.
  const [invalidError, setInvalidError] = useState('');
  useEffect(() => {
    setInvalidError('');
  }, [type]);

  const { showRequiredError, setShowRequiredError } = useRequiredProp(
    required,
    example,
    () => {
      // Map 'string' type to 'text' key in DEFAULT_EXAMPLES
      const exampleKey = type === 'string' ? 'text' : type;
      dispatch(
        updateProp({
          id,
          updates: { example: DEFAULT_EXAMPLES[exampleKey] },
        }),
      );
    },
    [dispatch, id, type],
  );

  return (
    <Flex direction="column" gap="4" flexGrow="1">
      <Divider />
      <FormElement>
        <Label htmlFor={`prop-example-${id}`}>Example value</Label>
        <TextField.Root
          autoComplete="off"
          id={`prop-example-${id}`}
          type={isNumeric ? 'number' : 'text'}
          step={type === 'integer' ? 1 : 'any'}
          placeholder={
            {
              string: 'Enter a text value',
              integer: 'Enter an integer',
              number: 'Enter a number',
            }[type]
          }
          value={example ?? ''}
          size="1"
          onChange={(e) => {
            const raw = e.target.value;
            if (isNumeric) {
              const error = getNumericInputError(raw, type);
              if (error) {
                setInvalidError(error);
                return;
              }
              setInvalidError('');
            }
            dispatch(
              updateProp({
                id,
                updates: { example: raw },
              }),
            );
            setShowRequiredError(required && !raw);
          }}
          disabled={isDisabled}
          className={clsx({
            [styles.error]: showRequiredError || !!invalidError,
          })}
          {...(showRequiredError || !!invalidError
            ? { 'data-invalid-prop-value': true }
            : {})}
        />
        {showRequiredError && (
          <Text color="red" size="1">
            {REQUIRED_EXAMPLE_ERROR_MESSAGE}
          </Text>
        )}
        {!!invalidError && !showRequiredError && (
          <Text color="red" size="1">
            {invalidError}
          </Text>
        )}
      </FormElement>
    </Flex>
  );
}
